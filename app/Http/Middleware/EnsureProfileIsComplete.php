<?php

namespace App\Http\Middleware;

use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards everything that needs a real identity behind it.
 *
 * `gender` and `registered_role` are nullable until minimal profile setup
 * runs, so any feature that reads them — women-only matching above all —
 * must not be reachable before then. This is the gate that makes the
 * nullable columns safe: a half-registered account can authenticate, and
 * can do nothing that depends on facts it has not supplied yet.
 */
final class EnsureProfileIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->profile_status !== ProfileStatus::BasicComplete) {
            throw DomainException::of(ErrorCode::ProfileIncomplete);
        }

        return $next($request);
    }
}
