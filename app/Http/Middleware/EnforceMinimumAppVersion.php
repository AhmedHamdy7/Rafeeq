<?php

namespace App\Http\Middleware;

use App\Domains\AppVersion\Enums\AppPlatform;
use App\Domains\AppVersion\Support\AppVersions;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server's half of a forced update: an app version that must update gets
 * `426 APP_UPDATE_REQUIRED` on every API call, so an old install cannot carry on just because it
 * skipped (or predates) its own check.
 *
 * 🔒 Opt-in by the app, on purpose. It acts ONLY when the request carries both
 * `X-App-Platform` (ios | android) and `X-App-Version`. Installs that do not send them — every
 * version shipped before this existed — are never refused here; for those, the app's own call to
 * GET /app/version is the check. A wrong or missing header is ignored, never an error.
 *
 * GET /app/version itself is always allowed, so a blocked app can still learn where to update.
 */
final class EnforceMinimumAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $platform = AppPlatform::tryFrom(strtolower(trim((string) $request->header('X-App-Platform'))));
        $version = $request->header('X-App-Version');

        if ($platform === null || $version === null || $request->is('api/v1/app/version')) {
            return $next($request);
        }

        if (AppVersions::decide(AppVersions::release($platform), $version)['updateRequired']) {
            throw DomainException::of(ErrorCode::AppUpdateRequired);
        }

        return $next($request);
    }
}
