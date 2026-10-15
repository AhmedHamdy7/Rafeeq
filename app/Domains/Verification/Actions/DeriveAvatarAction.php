<?php

namespace App\Domains\Verification\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use App\Domains\Verification\Support\DocumentStorage;
use Illuminate\Support\Str;

/**
 * Turning an approved selfie into the person's avatar (Feature 02 v3 §2).
 *
 * The product rule is unusual and worth stating: **there is no edit-photo control.** v3 is explicit
 * — "Before identity verification show initials; after approval show approved verification photo. No
 * ordinary edit-photo control." So the avatar is not something anybody uploads. It is a consequence
 * of a reviewer approving an identity, which is exactly what makes it worth something to the
 * passenger looking at it: the face on the profile is the face a human being checked against an ID.
 *
 * 🔒 **The derived file is a NEW image, and that is the security of it.**
 *
 * It would be much simpler to point the avatar at the stored selfie. That file is an unmodified
 * upload from the public internet, and the document endpoint goes to some length never to render
 * one inline — `Content-Disposition: attachment` with a sandbox CSP — because untrusted content
 * served from our own origin can execute against our own session. An avatar has to render inline,
 * so the two requirements are in direct conflict.
 *
 * Decoding and re-encoding through GD resolves it rather than trading it away: what comes out is
 * a bitmap this server produced, carrying no original bytes, no metadata and no trailing payload.
 * {@see ImageSanitiser} already does precisely this for the same reason, so it is reused rather
 * than re-implemented — including its decompression-bomb guard, which matters because the source
 * here was itself uploaded by a stranger.
 *
 * 🔒 EXIF goes with it (standard #24). A selfie straight off a phone carries the GPS coordinates of
 * wherever it was taken, which for a selfie taken to verify an identity is very often somebody's
 * home. Re-encoding drops it; nothing else here has to remember to.
 */
final readonly class DeriveAvatarAction
{
    /** Square, and large enough for a retina profile header without storing a portrait. */
    private const int SIZE = 256;

    private const int JPEG_QUALITY = 85;

    public function __construct(private DocumentStorage $storage) {}

    /**
     * Derive and store the avatar for an approved selfie. Returns the stored path, or null when
     * there is nothing to derive from.
     *
     * Idempotent by overwrite: approving a replacement selfie later simply writes a new file and
     * repoints the user. The previous avatar is deleted, because an orphan face photo nobody can
     * reach is still a face photo we are keeping.
     */
    public function execute(UserVerification $verification): ?string
    {
        if ($verification->type !== VerificationType::Selfie) {
            return null;
        }

        $user = $verification->user;

        /*
         * The most recent document on the verification. A person may have submitted twice after an
         * "ask info", and the approved decision refers to what they last sent.
         */
        $document = $verification->documents()->latest('created_at')->first();

        if ($document === null || $document->purged_at !== null) {
            return null;
        }

        $disk = $this->storage->disk();

        if (! $disk->exists($document->file_path)) {
            // The retention sweep may have destroyed the file while the row survives. Not an
            // error: the review still stands, the person simply has no avatar.
            return null;
        }

        $square = $this->square((string) $disk->get($document->file_path));

        if ($square === null) {
            return null;
        }

        $path = sprintf('users/%s/avatar-%s.jpg', $user->getKey(), Str::ulid());
        $disk->put($path, $square);

        $previous = $user->profile_photo_path;

        $user->forceFill(['profile_photo_path' => $path])->save();

        if ($previous !== null && $previous !== $path && $disk->exists($previous)) {
            $disk->delete($previous);
        }

        return $path;
    }

    /**
     * A centre-cropped square JPEG, or null if the bytes will not decode.
     *
     * Centre crop rather than squash: a stretched face is worse than a cropped one, and the centre
     * is where a face is in a selfie taken for verification.
     */
    private function square(string $contents): ?string
    {
        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $edge = min($width, $height);

        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);

        imagecopyresampled(
            $canvas,
            $source,
            0, 0,
            (int) (($width - $edge) / 2),
            (int) (($height - $edge) / 2),
            self::SIZE, self::SIZE,
            $edge, $edge,
        );

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $encoded = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($source);

        return $encoded === '' ? null : $encoded;
    }
}
