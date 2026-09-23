<?php

namespace App\Domains\Verification\Support;

use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use GdImage;

/**
 * Re-encodes an uploaded image, which is what actually strips its metadata.
 *
 * This is not cosmetic. A photo of an ID taken on a phone routinely carries
 * GPS coordinates in EXIF — the person's home, in most cases, since that is
 * where people photograph their documents. Storing that alongside their
 * national ID would hand an attacker who reached the bucket far more than the
 * document itself, and the person never agreed to share it.
 *
 * Decoding to a bitmap and encoding again keeps only the pixels: EXIF, XMP,
 * IPTC, colour profiles and any appended payload are all dropped, because
 * none of them survive a round trip through a raster buffer. That also
 * neutralises a file that is a valid image AND something else at once.
 *
 * Alpha is flattened onto white rather than preserved: a document photo has
 * no use for transparency, and JPEG cannot express it.
 */
final class ImageSanitiser
{
    private const int JPEG_QUALITY = 88;

    /**
     * The widest an ID photo needs to be for a reviewer to read it. Anything
     * larger is downscaled, which also caps what one upload can cost in
     * storage and in review bandwidth.
     */
    private const int MAX_DIMENSION = 2400;

    /**
     * @return array{contents: string, mime: string, width: int, height: int}
     */
    public function sanitise(string $contents): array
    {
        $source = @imagecreatefromstring($contents);

        if (! $source instanceof GdImage) {
            // Reached when the bytes are not a decodable image at all, which
            // includes a file that merely starts with an image signature.
            throw DomainException::of(ErrorCode::DocumentUnreadable);
        }

        $target = $this->flattenAndResize($source);

        ob_start();
        imagejpeg($target, null, self::JPEG_QUALITY);
        $encoded = (string) ob_get_clean();

        $width = imagesx($target);
        $height = imagesy($target);

        imagedestroy($target);

        if ($source !== $target) {
            imagedestroy($source);
        }

        return [
            'contents' => $encoded,
            // Always JPEG: one stored format means one decoder path for every
            // consumer, and no surprise from an exotic variant of another.
            'mime' => 'image/jpeg',
            'width' => $width,
            'height' => $height,
        ];
    }

    private function flattenAndResize(GdImage $source): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $scale = min(1.0, self::MAX_DIMENSION / max($sourceWidth, $sourceHeight));

        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        $target = imagecreatetruecolor($width, $height);

        // Without this, a transparent PNG flattens onto black and a scanned
        // document becomes unreadable for the reviewer.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));

        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        return $target;
    }
}
