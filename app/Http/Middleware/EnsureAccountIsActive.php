<?php

namespace App\Http\Middleware;

use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scenario H: a suspended account's tokens stay perfectly valid, so nothing
 * about the session itself stops it. This is what stops it.
 *
 * Applied to everything a suspended person must not do — bookings, publishing
 * a commute, editing a profile — and deliberately NOT to the handful of
 * routes they still need: reading their own status (to see the suspension),
 * signing out, and revoking a device. Blocking those would trap someone
 * outside any route to appeal, which the chapter explicitly rules out.
 *
 * It answers 403, never 401: a 401 would make the app discard a valid
 * session and bounce back to the phone screen, hiding the reason.
 */
final class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->account_status === AccountStatus::Suspended) {
            SecurityLog::record(SecurityEventType::SuspendedAccessAttempt, $user, metadata: [
                'path' => $request->path(),
            ]);

            throw DomainException::of(ErrorCode::AccountSuspended);
        }

        return $next($request);
    }
}
