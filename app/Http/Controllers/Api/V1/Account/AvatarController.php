<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Verification\Support\Avatar;
use App\Domains\Verification\Support\DocumentStorage;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AvatarController extends Controller
{
    /**
     * GET /v1/users/{user}/avatar — the image behind `avatarPath`.
     *
     * The guide said the display-URL flow for a profile photo was undecided. This decides it, and
     * the shape of the answer is dictated by two rules already in the codebase that pull in
     * opposite directions:
     *
     *   - identity documents are streamed with `Content-Disposition: attachment` and a sandbox CSP,
     *     because an unmodified upload served from our own origin can execute against our own
     *     session. An avatar must render INLINE, so that treatment is unavailable.
     *   - so the avatar is not that file. {@see DeriveAvatarAction} decodes and re-encodes it
     *     through GD, and what is served here is a bitmap this server produced — no original bytes,
     *     no metadata, no trailing payload. Rendering it inline is safe because of what it is, not
     *     because the rule was relaxed.
     *
     * 🔒 `nosniff` stays regardless. It costs nothing, and it closes the gap between "we believe
     * this is a JPEG" and "the browser agrees" without relying on the re-encode having been perfect.
     *
     * Served to any signed-in member on purpose: the avatar exists so a passenger can see the face
     * of the driver she is about to ride with, which is the one thing on the profile that is
     * actively reassuring.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function show(string $user): StreamedResponse
    {
        $person = User::query()->whereKey($user)->first();

        // Same 404 for missing, suspended and has-no-avatar — see `PublicProfileController` on why
        // these are not distinguished.
        if ($person === null || $person->account_status !== AccountStatus::Active) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        $path = Avatar::pathFor($person);

        if ($path === null) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        $disk = app(DocumentStorage::class)->disk();

        if (! $disk->exists($path)) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return $disk->response($path, headers: [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            /*
             * `private`, not `public`: the image is behind a bearer token, and a shared proxy cache
             * keyed on the URL alone would serve it to whoever asked next. An hour is long enough
             * to stop a member list re-fetching the same faces on every scroll.
             */
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
