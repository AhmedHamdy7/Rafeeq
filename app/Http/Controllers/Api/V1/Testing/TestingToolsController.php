<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Admin\Models\ContentPage;
use App\Domains\Identity\Enums\OtpStatus;
use App\Domains\Identity\Enums\SessionRevocationReason;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\AuthSettings;
use App\Domains\Identity\Support\CurrentSession;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Domains\Shared\Testing\TestingFaults;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\ContentPageResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Throwable;

final class TestingToolsController extends Controller
{
    private const array CONTENT_KEYS = ['support_contact' => 'support_contact', 'guidelines' => 'guidelines', 'community_guidelines' => 'guidelines'];

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::FaultLimitReached, ErrorCode::NotFound)]
    public function addFault(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'path' => ['required', 'string', 'max:191', 'starts_with:/'],
            'method' => ['sometimes', 'nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'get', 'post', 'put', 'patch', 'delete'])],
            'mode' => ['required', 'string', Rule::in(['delay', 'error'])],
            'delayMs' => ['required_if:mode,delay', 'nullable', 'integer', 'between:500,15000'],
            'status' => ['required_if:mode,error', 'nullable', 'integer', Rule::in(TestingFaults::ERROR_STATUSES)],
            'code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,63}$/'],
            'retryAfterSeconds' => ['sometimes', 'nullable', 'integer', 'between:1,3600'],
            'count' => ['sometimes', 'integer', 'between:1,10'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'fields' => ['sometimes', 'nullable', 'array', 'max:20'],
        ]);

        return ApiResponse::success($this->storeFault($request, $user, $data), status: 201);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::TestNumberOnly, ErrorCode::FaultLimitReached, ErrorCode::NotFound)]
    public function addPhoneFault(Request $request): JsonResponse
    {
        $phone = $this->testPhone($request);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:191', 'starts_with:/'],
            'method' => ['sometimes', 'nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'get', 'post', 'put', 'patch', 'delete'])],
            'mode' => ['required', 'string', Rule::in(['delay', 'error'])],
            'delayMs' => ['required_if:mode,delay', 'nullable', 'integer', 'between:500,15000'],
            'status' => ['required_if:mode,error', 'nullable', 'integer', Rule::in(TestingFaults::ERROR_STATUSES)],
            'code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,63}$/'],
            'retryAfterSeconds' => ['sometimes', 'nullable', 'integer', 'between:1,3600'],
            'count' => ['sometimes', 'integer', 'between:1,10'],
            'fields' => ['sometimes', 'nullable', 'array', 'max:20'],
        ]);

        $path = TestingFaults::normalise((string) $data['path']);

        if (! TestingFaults::isAllowedPath($path)) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'path' => ['/testing/* and /auth/* cannot be matched, except /auth/me, /auth/otp/request and /auth/otp/verify named exactly.'],
            ]);
        }

        if (TestingFaults::openCountForPhone($phone->e164) >= (int) config('rafeeq.testing.fault_limit', 10)) {
            throw DomainException::of(ErrorCode::FaultLimitReached);
        }

        return ApiResponse::success(TestingFaults::create([
            'user_id' => User::query()->where('phone_e164', $phone->e164)->value('id'),
            'token_id' => null,
            'phone_e164' => $phone->e164,
            ...$this->faultColumns($path, $data),
        ]), status: 201);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::TestNumberOnly, ErrorCode::NotFound)]
    public function listPhoneFaults(Request $request): JsonResponse
    {
        return ApiResponse::success(TestingFaults::listForPhone($this->testPhone($request)->e164));
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::TestNumberOnly, ErrorCode::NotFound)]
    public function clearPhoneFaults(Request $request): JsonResponse
    {
        return ApiResponse::success(['cleared' => TestingFaults::clearPhone($this->testPhone($request)->e164)]);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::FaultLimitReached, ErrorCode::NotFound)]
    public function rateLimit(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'path' => ['required', 'string', 'max:191', 'starts_with:/'],
            'method' => ['sometimes', 'nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'get', 'post', 'put', 'patch', 'delete'])],
            'retryAfterSeconds' => ['sometimes', 'integer', 'between:1,3600'],
            'count' => ['sometimes', 'integer', 'between:1,10'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        return ApiResponse::success($this->storeFault($request, $user, [
            ...$data,
            'mode' => 'error',
            'status' => 429,
            'code' => ErrorCode::TooManyRequests->value,
            'retryAfterSeconds' => $data['retryAfterSeconds'] ?? 30,
        ]), status: 201);
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function listFaults(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        return ApiResponse::success(TestingFaults::listFor($user->id));
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function clearFaults(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        return ApiResponse::success(['cleared' => TestingFaults::clear($user->id)]);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function revokeSession(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate(['refresh' => ['sometimes', 'boolean']]);
        $refresh = (bool) ($data['refresh'] ?? true);

        $token = $user->currentAccessToken();
        $session = CurrentSession::for($request);
        $revoked = 0;

        DB::transaction(function () use ($token, $session, $refresh, &$revoked): void {
            if ($refresh && $session !== null && ! $session->isRevoked()) {
                $session->revoke(SessionRevocationReason::Admin);
            }

            if ($token !== null && method_exists($token, 'delete')) {
                $token->delete();
            }

            $revoked = 1;
        });

        return ApiResponse::success([
            'revoked' => $revoked,
            'sessionId' => $session?->id,
            'refreshRevoked' => $refresh && $session !== null,
        ]);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::TestNumberOnly, ErrorCode::OtpNotRequested, ErrorCode::NotFound)]
    public function expireOtp(Request $request): JsonResponse
    {
        $phone = $this->testPhone($request);

        $challenge = OtpChallenge::query()
            ->where('phone_e164', $phone->e164)
            ->where('status', OtpStatus::Pending->value)
            ->latest('created_at')
            ->orderByDesc('id')
            ->first()
            ?? throw DomainException::of(ErrorCode::OtpNotRequested);

        $back = AuthSettings::otpTtlSeconds() + 1;

        DB::table('otp_challenges')->where('id', $challenge->id)->update([
            'created_at' => now()->subSeconds($back),
            'expires_at' => now()->subSecond(),
            'updated_at' => now(),
        ]);

        return ApiResponse::success([
            'phone' => $phone->e164,
            'challengeId' => $challenge->id,
            'expired' => true,
            'expiresAt' => now()->subSecond()->toIso8601String(),
        ]);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::TestNumberOnly, ErrorCode::NotFound)]
    public function unlockOtp(Request $request): JsonResponse
    {
        $phone = $this->testPhone($request);
        $cooldown = AuthSettings::otpResendCooldownSeconds();

        DB::transaction(function () use ($phone, $cooldown): void {
            DB::table('otp_challenges')
                ->where('phone_e164', $phone->e164)
                ->where('status', OtpStatus::Blocked->value)
                ->update(['status' => OtpStatus::Expired->value, 'locked_until' => null, 'updated_at' => now()]);

            DB::table('otp_challenges')
                ->where('phone_e164', $phone->e164)
                ->whereNotNull('locked_until')
                ->update(['locked_until' => null, 'updated_at' => now()]);

            DB::table('otp_challenges')
                ->where('phone_e164', $phone->e164)
                ->where('status', OtpStatus::Pending->value)
                ->update([
                    'attempt_count' => 0,
                    'resend_count' => 0,
                    'created_at' => DB::raw('LEAST(`created_at`, '.DB::getPdo()->quote(now()->subSeconds($cooldown + 1)->format('Y-m-d H:i:s')).')'),
                    'updated_at' => now(),
                ]);
        });

        RateLimiter::clear('otp:phone:'.hash('sha256', $phone->e164));

        OtpChallenge::query()
            ->where('phone_e164', $phone->e164)
            ->where('created_at', '>', now()->subDay())
            ->pluck('id')
            ->each(fn (string $id) => RateLimiter::clear('otp:verify:'.$id));

        return ApiResponse::success([
            'phone' => $phone->e164,
            'attempts' => 0,
            'lockedUntil' => null,
            'resendAvailableAt' => null,
        ]);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function writeContent(Request $request, string $key): JsonResponse
    {
        TestAccounts::assertAllowed($request->user());
        $key = self::CONTENT_KEYS[$key] ?? throw DomainException::of(ErrorCode::NotFound);

        $data = $request->validate([
            'en' => ['required', 'string', 'max:5000'],
            'ar' => ['required', 'string', 'max:5000'],
        ]);

        $page = DB::transaction(function () use ($key, $data): ContentPage {
            $page = ContentPage::query()->lockForUpdate()->find($key);

            $this->stashOriginal($key, $page);

            if ($page === null) {
                $page = new ContentPage;
                $page->key = $key;
                $page->version = 1;
            } else {
                $page->version = (int) $page->version + 1;
            }

            $page->body_en = $data['en'];
            $page->body_ar = $data['ar'];
            $page->updated_by = null;
            $page->save();

            return $page;
        });

        return ApiResponse::success(new ContentPageResource($page->refresh()));
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function removeContent(Request $request, string $key): JsonResponse
    {
        TestAccounts::assertAllowed($request->user());
        $key = self::CONTENT_KEYS[$key] ?? throw DomainException::of(ErrorCode::NotFound);

        $original = Cache::get(self::backupKey($key));

        DB::transaction(function () use ($key, $original): void {
            if (! is_array($original) || ! ($original['existed'] ?? false)) {
                ContentPage::query()->whereKey($key)->delete();

                return;
            }

            $page = ContentPage::query()->find($key) ?? tap(new ContentPage, fn (ContentPage $p) => $p->key = $key);
            $page->body_en = (string) $original['body_en'];
            $page->body_ar = (string) $original['body_ar'];
            $page->version = (int) $original['version'];
            $page->updated_by = $original['updated_by'];
            $page->save();
        });

        Cache::forget(self::backupKey($key));

        $restored = is_array($original) && ($original['existed'] ?? false);

        return ApiResponse::success([
            'key' => $key,
            'removed' => ! $restored,
            'restoredStaffVersion' => $restored,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function storeFault(Request $request, User $user, array $data): array
    {
        $path = TestingFaults::normalise((string) $data['path']);

        if (! TestingFaults::isAllowedPath($path)) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'path' => ['/testing/* and /auth/* cannot be matched, except /auth/me, /auth/otp/request and /auth/otp/verify named exactly.'],
            ]);
        }

        if (TestingFaults::openCount($user->id) >= (int) config('rafeeq.testing.fault_limit', 10)) {
            throw DomainException::of(ErrorCode::FaultLimitReached);
        }

        $otp = TestingFaults::isOtpPath($path);
        $phone = null;

        if ($otp) {
            $phone = $user->phone_e164;

            if (! empty($data['phone'])) {
                try {
                    $phone = PhoneNumber::fromRaw((string) $data['phone'])->e164;
                } catch (Throwable) {
                    throw DomainException::of(ErrorCode::ValidationFailed, fields: ['phone' => [__('errors.AUTH_PHONE_INVALID')]]);
                }

                if ($phone !== $user->phone_e164 && ! TestAccounts::isTestPhone($phone)) {
                    throw DomainException::of(ErrorCode::TestNumberOnly);
                }
            }
        }

        $token = $user->currentAccessToken();
        $tokenId = $otp ? null : ($token !== null && isset($token->id) ? (int) $token->id : null);

        if (! $otp && $tokenId === null) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return TestingFaults::create([
            'user_id' => $user->id,
            'token_id' => $tokenId,
            'phone_e164' => $phone,
            ...$this->faultColumns($path, $data),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function faultColumns(string $path, array $data): array
    {
        $fields = null;

        if ($data['mode'] === 'error' && ! empty($data['fields']) && is_array($data['fields'])) {
            $fields = [];

            foreach ($data['fields'] as $key => $value) {
                $messages = array_values(array_filter(
                    array_map(fn ($item): ?string => is_scalar($item) ? mb_substr((string) $item, 0, 255) : null, is_array($value) ? $value : [$value]),
                    fn (?string $item): bool => $item !== null,
                ));

                if (! is_string($key) || $key === '' || strlen($key) > 64 || $messages === []) {
                    throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                        'fields' => ['Each key needs a string or a list of strings, as in error.fields.'],
                    ]);
                }

                $fields[$key] = $messages;
            }
        }

        return [
            'path' => $path,
            'method' => isset($data['method']) ? strtoupper((string) $data['method']) : null,
            'mode' => $data['mode'],
            'delay_ms' => $data['mode'] === 'delay' ? (int) $data['delayMs'] : null,
            'status' => $data['mode'] === 'error' ? (int) $data['status'] : null,
            'code' => $data['mode'] === 'error' ? ($data['code'] ?? null) : null,
            'retry_after_seconds' => $data['mode'] === 'error' && (int) $data['status'] === 429 ? (int) ($data['retryAfterSeconds'] ?? 30) : null,
            'fields' => $fields === null ? null : json_encode($fields, JSON_UNESCAPED_UNICODE),
            'remaining' => (int) ($data['count'] ?? 1),
        ];
    }

    private function testPhone(Request $request): PhoneNumber
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);

        try {
            $phone = PhoneNumber::fromRaw((string) $data['phone']);
        } catch (Throwable) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: ['phone' => [__('errors.AUTH_PHONE_INVALID')]]);
        }

        if (! TestAccounts::isTestPhone($phone->e164)) {
            throw DomainException::of(ErrorCode::TestNumberOnly);
        }

        return $phone;
    }

    private function stashOriginal(string $key, ?ContentPage $page): void
    {
        if (Cache::has(self::backupKey($key))) {
            return;
        }

        Cache::forever(self::backupKey($key), $page === null ? ['existed' => false] : [
            'existed' => true,
            'body_en' => $page->body_en,
            'body_ar' => $page->body_ar,
            'version' => (int) $page->version,
            'updated_by' => $page->updated_by,
        ]);
    }

    private static function backupKey(string $key): string
    {
        return "testing:content:original:{$key}";
    }
}
