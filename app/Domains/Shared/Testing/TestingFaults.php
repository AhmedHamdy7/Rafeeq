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

    public const array ERROR_STATUSES = [404, 409, 422, 429, 500, 503];

    public static function isOtpPath(string $path): bool
    {
        return in_array(self::normalise($path), self::OTP_PATHS, true);
    }

    public static function isAllowedPath(string $path): bool
    {
        $path = self::normalise($path);

        if (self::isOtpPath($path)) {
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
        return $prefix === '/' || $path === $prefix || str_starts_with($path, $prefix.'/');
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
            ->where(function ($query) use ($request, $path): void {
                $tokenId = self::tokenIdOf($request);
                $phone = self::isOtpPath($path) ? self::phoneOf($request, $path) : null;

                $query->whereRaw('1 = 0');

                if ($tokenId !== null) {
                    $query->orWhere('token_id', $tokenId);
                }

                if ($phone !== null) {
                    $query->orWhere(fn ($q) => $q->whereNull('token_id')->where('phone_e164', $phone));
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $rule) {
            if (! self::within($path, $rule->path)) {
                continue;
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

    private static function phoneOf(Request $request, string $path): ?string
    {
        try {
            if ($path === '/auth/otp/request') {
                $raw = $request->input('phone');

                return is_string($raw) && $raw !== '' ? PhoneNumber::fromRaw($raw)->e164 : null;
            }

            $challengeId = $request->input('challengeId');

            return is_string($challengeId) && $challengeId !== ''
                ? OtpChallenge::query()->whereKey($challengeId)->value('phone_e164')
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
