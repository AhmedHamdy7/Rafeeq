<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A staff member changes their own password (My account, MY1).
 *
 * 🔒 The current password is asked for again even though the session is signed in: a
 * browser left open for a minute should not be enough to take the account over. Wrong
 * guesses are limited like sign-in, and the change is in the audit log (never the
 * password itself).
 */
final class ChangeAdminPasswordAction
{
    public const int MIN_LENGTH = 12;

    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 900;

    public function execute(AdminUser $admin, string $current, string $new): void
    {
        $key = 'admin-password-change:'.$admin->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'current' => __('admin/account.password.too_many', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
            ]);
        }

        if (! Hash::check($current, (string) $admin->password_hash)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages(['current' => __('admin/account.password.wrong_current')]);
        }

        if (Hash::check($new, (string) $admin->password_hash)) {
            throw ValidationException::withMessages(['new' => __('admin/account.password.same')]);
        }

        RateLimiter::clear($key);

        $admin->forceFill(['password_hash' => Hash::make($new)])->save();

        AdminActionLog::record($admin, 'admin.password_changed', $admin);
    }
}
