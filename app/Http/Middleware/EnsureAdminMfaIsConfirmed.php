<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🔒 The second factor, enforced on every admin request rather than only at sign-in.
 *
 * `AuthenticateAdminAction` will not sign anybody in without a valid TOTP code, so in
 * the ordinary flow this never fires. It exists for the paths that bypass that Action
 * entirely: a session restored from a cookie, a future SSO or password-reset flow that
 * calls `Auth::login()` directly, a test helper, a console command. Each of those is a
 * perfectly reasonable thing for somebody to add later, and each would silently produce
 * an authenticated admin who never presented a second factor.
 *
 * `admin_users.mfa_secret` is NOT NULL and Chapter 12 lists MFA as a requirement, so
 * the rule is that an admin session without a confirmed second factor is not an admin
 * session — checked here, where no new code path can forget it.
 */
final class EnsureAdminMfaIsConfirmed
{
    public const string PASSED = 'admin.mfa_passed';

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if ($admin === null) {
            return redirect()->route('admin.login');
        }

        // Enrolment, and whether THIS session proved it. Both, because a confirmed
        // secret says the account can generate codes and the session flag says this
        // browser actually did.
        if (! $admin->hasMfaConfirmed() || $request->session()->get(self::PASSED) !== true) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('admin.login')
                ->with('status', __('admin.session.mfa_required'));
        }

        return $next($request);
    }
}
