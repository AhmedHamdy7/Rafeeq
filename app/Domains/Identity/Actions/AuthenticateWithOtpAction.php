<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\AccountState;
use App\Domains\Identity\Enums\ConsentSource;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\AuthenticationResult;
use App\Domains\Identity\Support\DeviceIdentity;
use App\Domains\Identity\Support\LaunchRouter;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Chapter 2 §14: ONE flow for registration and sign-in.
 *
 * The chapter is emphatic that these must not be two screens or two
 * endpoints, and the reason is a security property, not a UX preference: a
 * separate "register" path would answer differently for a number that
 * already has an account, which is precisely the enumeration oracle
 * scenario D forbids. Here the branch happens only after the code is
 * verified, and both branches return the same shape.
 */
final readonly class AuthenticateWithOtpAction
{
    public function __construct(
        private VerifyOtpAction $verifyOtp,
        private RegisterDeviceAction $registerDevice,
        private IssueSessionAction $issueSession,
        private RecordConsentAction $recordConsent,
    ) {}

    public function execute(string $challengeId, string $code, DeviceIdentity $identity): AuthenticationResult
    {
        // Both purposes are accepted here: "forgot PIN" (scenario C) is the
        // same proof-of-phone, spent on a different outcome below. A code
        // issued for a phone change is NOT accepted — that one is spent by
        // its own endpoint, on an already-authenticated caller.
        $challenge = $this->verifyOtp->execute($challengeId, $code, [
            OtpPurpose::Authentication,
            OtpPurpose::PinReset,
        ]);

        $phone = PhoneNumber::fromRaw($challenge->phone_e164);

        return DB::transaction(function () use ($phone, $challenge, $identity): AuthenticationResult {
            [$user, $accountState] = $this->resolveAccount($phone);

            $device = $this->registerDevice->execute($user, $identity);

            if ($challenge->purpose === OtpPurpose::PinReset) {
                // Scenario C: the old local verifier is gone. The server only
                // ever knew THAT a PIN existed, so forgetting it is this one
                // flag — and the app is then routed to CREATE_PIN.
                $device->forceFill(['has_local_pin' => false, 'biometric_enabled' => false])->save();

                SecurityLog::record(SecurityEventType::LocalPinReset, $user, $device);
            }

            $session = $this->issueSession->execute($user, $device);

            SecurityLog::record(SecurityEventType::LoginSuccess, $user, $device, [
                'account_state' => $accountState->value,
            ]);

            return new AuthenticationResult(
                user: $user,
                device: $device,
                accountState: $accountState,
                nextStep: LaunchRouter::nextStepFor($user, $device),
                session: $session,
            );
        });
    }

    /**
     * @return array{0: User, 1: AccountState}
     */
    private function resolveAccount(PhoneNumber $phone): array
    {
        // Soft-deleted accounts are deliberately NOT matched: the
        // `phone_e164_active` generated column frees the number on deletion,
        // so a returning person gets a clean account rather than one
        // resurrected with history they asked to have removed.
        $existing = User::query()->where('phone_e164', $phone->e164)->first();

        if ($existing !== null) {
            // A returning user's phone is re-proved on every sign-in; keeping
            // the original timestamp would misreport when it was last shown.
            $existing->forceFill(['phone_verified_at' => now()])->save();

            return [$existing, AccountState::ExistingUser];
        }

        $user = User::create([
            'phone_e164' => $phone->e164,
            // Chapter 2 §20.1 marks these "after setup"/"policy-based", and
            // the migration's CHECK constraint guarantees a profile cannot
            // be marked complete while they are still unknown. NULL here
            // means "not answered yet" — never a placeholder, which for
            // `gender` would silently misfile someone in a safety filter.
            'preferred_language' => app()->getLocale(),
        ]);

        $user->forceFill(['phone_verified_at' => now()])->save();

        // `create()` returns the model as INSERTed, so columns whose value
        // comes from a database default (`account_status`, `profile_status`,
        // `trust_level`) are still unset on the instance. Reading one back
        // is what the response does next, so hydrate here rather than let
        // every consumer guess at a fallback.
        $user->refresh();

        $this->recordConsent->execute($user, ConsentSource::Registration);

        SecurityLog::record(SecurityEventType::AccountCreated, $user, metadata: [
            'phone' => SecurityLog::maskPhone($phone),
        ]);

        return [$user, AccountState::NewUser];
    }
}
