<?php

namespace App\Domains\Shared\Testing;

use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use stdClass;
use Throwable;

final class TestingFaults
{
    public const array OTP_PATHS = ['/auth/otp/request', '/auth/otp/verify'];

    public const array TOKEN_AUTH_PATHS = ['/auth/me'];

    public const array ERROR_STATUSES = [404, 409, 422, 429, 500, 503];

    public static function isOtpPath(string $path): bool
    {
        return in_array(self::normalise($path), self::OTP_PATHS, true);
    }

    public static function isAllowedPath(string $path): bool
    {
        $path = self::normalise($path);

        if (self::isOtpPath($path) || in_array($path, self::TOKEN_AUTH_PATHS, true)) {
            return true;
        }

        return ! self::within($path, '/testing') && ! self::within($path, '/auth');
    }

    public static function normalise(string $path): string
    {
        $path = '/'.trim(strtolower(trim($path)), '/');

        return preg_replace('#/+#', '/', $path) ?? $path;
    }

    public static function within(string $path, string $prefix): bool
    {
        if (! str_contains($prefix, '*')) {
            return $prefix === '/' || $path === $prefix || str_starts_with($path, $prefix.'/');
        }

        $want = explode('/', trim($prefix, '/'));
        $have = explode('/', trim($path, '/'));

        if (count($have) < count($want)) {
            return false;
        }

        foreach ($want as $i => $segment) {
            if ($segment !== '*' && $segment !== $have[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function create(array $attributes): array
    {
        $id = (string) Str::ulid();

        DB::table('testing_faults')->insert([
            'id' => $id,
            ...$attributes,
            'expires_at' => now()->addSeconds((int) config('rafeeq.testing.fault_ttl_seconds', 300)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return self::present(DB::table('testing_faults')->where('id', $id)->first());
    }

    public static function openCount(string $userId): int
    {
        return DB::table('testing_faults')
            ->where('user_id', $userId)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listFor(string $userId): array
    {
        return DB::table('testing_faults')
            ->where('user_id', $userId)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): array => self::present($row))
            ->values()
            ->all();
    }

    public static function clear(string $userId): int
    {
        return DB::table('testing_faults')->where('user_id', $userId)->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForPhone(string $phone): array
    {
        return DB::table('testing_faults')
            ->whereNull('token_id')
            ->where('phone_e164', $phone)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): array => self::present($row))
            ->values()
            ->all();
    }

    public static function openCountForPhone(string $phone): int
    {
        return DB::table('testing_faults')
            ->whereNull('token_id')
            ->where('phone_e164', $phone)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->count();
    }

    public static function clearPhone(string $phone): int
    {
        return DB::table('testing_faults')->whereNull('token_id')->where('phone_e164', $phone)->delete();
    }

    public static function claim(Request $request): ?stdClass
    {
        static $tableExists = null;

        $tableExists ??= Schema::hasTable('testing_faults');

        if (! $tableExists) {
            return null;
        }

        $path = self::relativePath($request);

        if ($path === null || ! self::isAllowedPath($path)) {
            return null;
        }

        $candidates = DB::table('testing_faults')
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->where(function ($query) use ($request): void {
                $tokenId = self::tokenIdOf($request);

                $query->where(fn ($q) => $q->whereNull('token_id')->whereNotNull('phone_e164'));

                if ($tokenId !== null) {
                    $query->orWhere('token_id', $tokenId);
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $phones = null;

        foreach ($candidates as $rule) {
            if (! self::within($path, $rule->path)) {
                continue;
            }

            if ($rule->token_id === null) {
                $phones ??= self::phonesOf($request, $path);

                if (! in_array($rule->phone_e164, $phones, true)) {
                    continue;
                }
            }

            if ($rule->method !== null && strtoupper($rule->method) !== strtoupper($request->method())) {
                continue;
            }

            if ($rule->token_id !== null && ! self::tokenIsGenuine($request, (int) $rule->token_id)) {
                continue;
            }

            $taken = DB::table('testing_faults')
                ->where('id', $rule->id)
                ->where('remaining', '>', 0)
                ->update(['remaining' => DB::raw('remaining - 1'), 'updated_at' => now()]);

            if ($taken === 1) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(?stdClass $row): array
    {
        if ($row === null) {
            return [];
        }

        return [
            'id' => $row->id,
            'path' => $row->path,
            'method' => $row->method,
            'mode' => $row->mode,
            'delayMs' => $row->delay_ms === null ? null : (int) $row->delay_ms,
            'status' => $row->status === null ? null : (int) $row->status,
            'code' => $row->code,
            'retryAfterSeconds' => $row->retry_after_seconds === null ? null : (int) $row->retry_after_seconds,
            'fields' => self::fieldsOf($row),
            'phone' => $row->phone_e164,
            'remaining' => (int) $row->remaining,
            'expiresAt' => CarbonImmutable::parse($row->expires_at)->toIso8601String(),
        ];
    }

    public static function relativePath(Request $request): ?string
    {
        $path = '/'.ltrim($request->path(), '/');

        if (! str_starts_with($path, '/api/v1/') && $path !== '/api/v1') {
            return null;
        }

        return self::normalise(substr($path, strlen('/api/v1')) ?: '/');
    }

    private static function tokenIdOf(Request $request): ?int
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || ! str_contains($bearer, '|')) {
            return null;
        }

        $id = explode('|', $bearer, 2)[0];

        return ctype_digit($id) ? (int) $id : null;
    }

    private static function tokenIsGenuine(Request $request, int $tokenId): bool
    {
        $bearer = $request->bearerToken();

        if ($bearer === null) {
            return false;
        }

        $token = PersonalAccessToken::findToken($bearer);

        return $token !== null && (int) $token->getKey() === $tokenId;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public static function fieldsOf(stdClass $row): ?array
    {
        $raw = $row->fields ?? null;

        if ($raw === null) {
            return null;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : (array) $raw;

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /**
     * @return list<string>
     */
    private static function phonesOf(Request $request, string $path): array
    {
        $phones = [];

        try {
            $raw = $request->input('phone');

            if (is_string($raw) && $raw !== '') {
                $phones[] = PhoneNumber::fromRaw($raw)->e164;
            }
        } catch (Throwable) {
        }

        try {
            $challengeId = $request->input('challengeId');

            if (is_string($challengeId) && $challengeId !== '') {
                $phones[] = (string) OtpChallenge::query()->whereKey($challengeId)->value('phone_e164');
            }
        } catch (Throwable) {
        }

        if ($path !== '/auth/otp/request' && $path !== '/auth/otp/verify' && $request->bearerToken() !== null) {
            $owner = PersonalAccessToken::findToken((string) $request->bearerToken())?->tokenable;

            if ($owner !== null && isset($owner->phone_e164)) {
                $phones[] = (string) $owner->phone_e164;
            }
        }

        return array_values(array_filter(array_unique($phones), fn (string $phone): bool => $phone !== ''));
    }
}
