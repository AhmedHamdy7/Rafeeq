<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Enums\AdminStatus;
use App\Domains\Admin\Models\AdminInvitation;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Admin\Support\AdminMfa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * The first sign-in (S05, S06): a password of their own, and an authenticator proved by a
 * code from it. Nothing is written until both are right — a person who chose a password
 * and closed the tab has not half-joined the team.
 *
 * Wrong codes are limited per invitation, like sign-in, so a stolen link cannot be used to
 * guess the six digits.
 */
final class AcceptAdminInvitationAction
{
    private const int MAX_CODE_ATTEMPTS = 5;

    public function __construct(private Google2FA $google2fa) {}

    public function accept(AdminInvitation $invitation, string $password, ?string $code): AdminUser
    {
        if (! $invitation->isUsable()) {
            throw ValidationException::withMessages(['code' => __('admin/team.invitation.expired')]);
        }

        $admin = $invitation->admin;
        $timestamp = null;

        if (AdminMfa::enabled()) {
            $key = 'admin-invitation:'.$invitation->id;

            if (RateLimiter::tooManyAttempts($key, self::MAX_CODE_ATTEMPTS)) {
                throw ValidationException::withMessages(['code' => __('admin/team.invitation.too_many')]);
            }

            $timestamp = $this->google2fa->verifyKeyNewer(
                secret: $admin->mfa_secret,
                key: (string) $code,
                oldTimestamp: 0,
                window: (int) config('rafeeq.admin.mfa_window'),
            );

            if ($timestamp === false) {
                RateLimiter::hit($key, 900);

                throw ValidationException::withMessages(['code' => __('admin/team.invitation.wrong_code')]);
            }
        }

        return DB::transaction(function () use ($invitation, $admin, $password, $timestamp) {
            $wasSuspended = $admin->status === AdminStatus::Suspended;

            $admin->forceFill([
                'password_hash' => Hash::make($password),
                'mfa_confirmed_at' => AdminMfa::enabled() ? now() : $admin->mfa_confirmed_at,
                'mfa_last_used_timestamp' => is_int($timestamp) ? $timestamp : $admin->mfa_last_used_timestamp,
                // A reset never lifts a suspension: only "Reactivate" does.
                'status' => $wasSuspended ? AdminStatus::Suspended : AdminStatus::Active,
            ])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            AdminActionLog::record($admin, 'admin.invitation_accepted', $admin);

            return $admin;
        });
    }
}
