<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🔒 Chapter 12 §Security: "session timeout".
 *
 * An admin browser left open is a different order of risk from a member's app left
 * open: it can approve drivers, read every identity document on the platform, and
 * suspend accounts. Ops staff work on shared machines and walk away from them, so the
 * session has to close itself rather than relying on anybody remembering to sign out.
 *
 * Idle time, not absolute age — the clock resets on each request, so this logs out the
 * screen nobody is sitting at and never interrupts somebody mid-review.
 *
 * Implemented here rather than with `config('session.lifetime')` because that value is
 * shared with every other session in the app; the admin guard needs its own, much
 * shorter, limit.
 */
final class EnsureAdminSessionIsFresh
{
    private const string LAST_SEEN = 'admin.last_seen_at';

    public function handle(Request $request, Closure $next): Response
    {
        $idleLimit = (int) config('rafeeq.admin.session_idle_minutes') * 60;

        $lastSeen = $request->session()->get(self::LAST_SEEN);

        /*
         * `now()`, not `time()`. Everything else in this codebase reads the clock
         * through Carbon, and PHP's `time()` ignores it entirely — which made this
         * timeout both inconsistent and impossible to test, since travelling the clock
         * moved every other date in the application and not this one.
         */
        if ($lastSeen !== null && (now()->timestamp - (int) $lastSeen) > $idleLimit) {
            Auth::guard('admin')->logout();

            /*
             * The whole session is invalidated and the token regenerated, not just the
             * guard cleared: anything else left in it (a pending MFA challenge, a
             * half-filled form holding somebody's data) belongs to the person who
             * walked away.
             */
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('admin.login')
                ->with('status', __('admin.session.expired'));
        }

        $request->session()->put(self::LAST_SEEN, now()->timestamp);

        return $next($request);
    }
}
