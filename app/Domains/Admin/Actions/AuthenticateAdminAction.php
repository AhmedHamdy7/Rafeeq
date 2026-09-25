<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/**
 * Signing a staff member in: password, then a TOTP code. Both, always.
 *
 * `admin_users.mfa_secret` is NOT NULL in the schema and Chapter 12 §Security lists
 * multi-factor authentication as a requirement, so there is no path through this class
 * that produces an authenticated admin from a password alone. The two steps are
 * separate methods because they are separate requests — the browser holds nothing but
 * a short-lived challenge in between.
 *
 * 🔴 What an admin account is worth, stated plainly, because it justifies the rest of
 * this file: one of these can read every identity document on the platform, approve a
 * driver, and suspend anyone. It is a far more valuable target than any single member's
 * account, and it is reachable from the internet with an email address.
 *
 * So:
 *
 * - the password check runs even when the email does not exist, against a dummy hash,
 *   so response time does not say which emails are real
 * - failures are counted per email AND per IP, because either one alone is trivially
 *   sidestepped
 * - a wrong password and a wrong TOTP code give the same message, because telling
 *   somebody their password was right is telling them half the answer
 * - a used TOTP code cannot be replayed, which the library does not do for us
 */
final readonly class AuthenticateAdminAction
{
    /**
     * Deliberately stricter than the mobile app's limiter. There is no legitimate
     * reason for a staff member to fail five password attempts in fifteen minutes,
     * and a lockout here inconveniences one person while the alternative is an
     * unlimited guessing budget against the most valuable account on the platform.
     */
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 900;

    public function __construct(private Google2FA $google2fa) {}

    /**
     * Step one. Returns the admin WITHOUT signing them in — the caller holds the id in
     * the session as a pending challenge and nothing else.
     */
    public function verifyPassword(string $email, string $password, ?string $ip): AdminUser
    {
        $this->assertNotThrottled($email, $ip);

        $admin = AdminUser::query()->where('email', $email)->first();

        /*
         * The hash comparison runs whether or not the account exists. Returning early
         * on an unknown email makes the response measurably faster for wrong emails
         * than for wrong passwords, which turns this endpoint into an oracle for which
         * staff addresses are real — the first thing a targeted attack wants.
         */
        $hash = $admin?->password_hash ?? Hash::make('not-a-real-password');

        if (! Hash::check($password, $hash) || $admin === null) {
            $this->recordFailure($email, $ip);

            throw DomainException::of(ErrorCode::AdminCredentialsInvalid);
        }

        if (! $admin->isActive()) {
            $this->recordFailure($email, $ip);

            // Same code as bad credentials: a suspended admin's session may have been
            // taken, and confirming the address belongs to real staff helps whoever
            // took it.
            throw DomainException::of(ErrorCode::AdminCredentialsInvalid);
        }

        if (! $admin->hasMfaConfirmed()) {
            /*
             * An admin who never finished enrolling has a secret but has not proved
             * they can generate codes from it. Letting them through on the password
             * alone would make MFA optional in practice for exactly the accounts most
             * likely to have been created in a hurry.
             */
            throw DomainException::of(ErrorCode::AdminMfaNotEnrolled);
        }

        return $admin;
    }

    /**
     * Step two. A correct code clears the throttle and stamps the login; a wrong one
     * counts against it exactly as a wrong password does.
     */
    public function verifyMfaCode(AdminUser $admin, string $code, ?string $ip): AdminUser
    {
        $this->assertNotThrottled($admin->email, $ip);

        $window = (int) config('rafeeq.admin.mfa_window');

        $timestamp = $this->google2fa->verifyKeyNewer(
            secret: $admin->mfa_secret,
            key: $code,
            oldTimestamp: $admin->mfa_last_used_timestamp ?? 0,
            window: $window,
        );

        /*
         * `verifyKeyNewer` returns false for a wrong code and, for a correct one, the
         * timestamp it matched — which is then stored so the SAME code cannot be used
         * twice. Without that, a code shoulder-surfed or captured from a proxy stays
         * valid for the rest of its 30-second step plus the window either side, and
         * TOTP's whole promise is that a code is spent once.
         */
        if ($timestamp === false) {
            $this->recordFailure($admin->email, $ip);

            throw DomainException::of(ErrorCode::AdminCredentialsInvalid);
        }

        RateLimiter::clear($this->emailKey($admin->email));

        $admin->forceFill([
            'mfa_last_used_timestamp' => $timestamp,
            'last_login_at' => now(),
            'last_login_ip_hash' => SecurityLog::hashIp($ip),
        ])->save();

        AdminActionLog::record($admin, 'admin.login', $admin);

        return $admin;
    }

    private function assertNotThrottled(string $email, ?string $ip): void
    {
        foreach ([$this->emailKey($email), $this->ipKey($ip)] as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                throw DomainException::retryAfter(
                    ErrorCode::TooManyRequests,
                    RateLimiter::availableIn($key),
                );
            }
        }
    }

    private function recordFailure(string $email, ?string $ip): void
    {
        RateLimiter::hit($this->emailKey($email), self::DECAY_SECONDS);
        RateLimiter::hit($this->ipKey($ip), self::DECAY_SECONDS);
    }

    private function emailKey(string $email): string
    {
        // Hashed so the limiter's own cache keys are not a list of staff addresses.
        return 'admin-login:email:'.hash('sha256', mb_strtolower($email));
    }

    private function ipKey(?string $ip): string
    {
        return 'admin-login:ip:'.hash('sha256', (string) $ip);
    }
}
