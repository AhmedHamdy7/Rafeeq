<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\AppVersion\Enums\AppPlatform;
use App\Domains\AppVersion\Support\AppVersions;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AppVersionController extends Controller
{
    /**
     * GET /v1/app/version?platform=ios&version=1.3.0 — what this install should do.
     *
     * Public (no token): the app asks before sign-in, on every launch. Staff set the numbers on
     * the dashboard's App versions page.
     *
     * - `updateRequired: true` → block the app and send her to `storeUrl`.
     * - `updateAvailable: true` (and not required) → suggest the update; she may skip it.
     * - `configured: false` → nothing is set for this store yet; carry on.
     *
     * Versions compare as numbers part by part (1.10.0 is newer than 1.9.0). A build suffix
     * (`1.3.0+45`) is ignored. Without `version`, the policy comes back and both flags are false.
     */
    #[ApiErrors(ErrorCode::ValidationFailed)]
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::enum(AppPlatform::class)],
            'version' => ['nullable', 'string', 'max:30'],
        ]);

        $platform = AppPlatform::from($data['platform']);
        $release = AppVersions::release($platform);
        $decision = AppVersions::decide($release, $data['version'] ?? null);
        $arabic = app()->getLocale() === 'ar';

        return ApiResponse::success([
            'platform' => $platform->value,
            'configured' => $decision['configured'],
            'currentVersion' => AppVersions::normalise($data['version'] ?? null),
            'latestVersion' => $release?->latest_version,
            'minimumVersion' => $release?->minimum_version,
            'forceUpdate' => (bool) $release?->force_update,
            'updateRequired' => $decision['updateRequired'],
            'updateAvailable' => $decision['updateAvailable'],
            'storeUrl' => $release?->store_url,
            // What is new, in the language the app asked for (Accept-Language), else the other one.
            'releaseNotes' => $release === null ? null : (($arabic ? $release->notes_ar : $release->notes_en) ?: ($arabic ? $release->notes_en : $release->notes_ar) ?: null),
        ]);
    }
}
