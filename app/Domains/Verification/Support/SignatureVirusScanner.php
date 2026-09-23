<?php

namespace App\Domains\Verification\Support;

use App\Domains\Verification\Contracts\VirusScanner;
use RuntimeException;

/**
 * Development scanner: refuses anything that is not a plain image.
 *
 * This is NOT antivirus and does not pretend to be. It enforces the half of
 * Chapter 3 §14 that can be enforced without an engine — "reject executable
 * files" — by checking the actual leading bytes rather than trusting the
 * extension or the declared MIME type, both of which the uploader controls.
 *
 * It refuses to run in production for the same reason `LogOtpSender` does: a
 * deploy that forgot to configure a real engine must fail loudly instead of
 * silently accepting whatever arrives.
 */
final class SignatureVirusScanner implements VirusScanner
{
    /**
     * Leading bytes of the formats we accept. Anything else — including a
     * script, an archive, or a PDF with an embedded payload — is refused.
     */
    private const array IMAGE_SIGNATURES = [
        "\xFF\xD8\xFF",          // JPEG
        "\x89PNG\r\n\x1A\n",     // PNG
        'RIFF',                  // WebP (container; the format is checked below)
    ];

    /**
     * Markers that mean "this is executable code", checked even inside a file
     * whose header looks like an image: a polyglot is the obvious way past a
     * header-only check.
     */
    private const array EXECUTABLE_MARKERS = [
        'MZ',            // Windows PE
        "\x7FELF",       // Linux ELF
        "\xCA\xFE\xBA\xBE", // Mach-O fat binary
        '#!',            // shell script
        '<?php',
        '<script',
    ];

    public function isClean(string $contents, string $originalName): bool
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'SignatureVirusScanner is a development stand-in and must never run in production. '
                .'Configure a real engine via rafeeq.verification.virus_scanner.'
            );
        }

        if ($contents === '' || ! $this->looksLikeImage($contents)) {
            return false;
        }

        foreach (self::EXECUTABLE_MARKERS as $marker) {
            if (str_contains($contents, $marker)) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeImage(string $contents): bool
    {
        foreach (self::IMAGE_SIGNATURES as $signature) {
            if (! str_starts_with($contents, $signature)) {
                continue;
            }

            // A RIFF container is only a WebP when it says so at offset 8.
            return $signature !== 'RIFF' || substr($contents, 8, 4) === 'WEBP';
        }

        return false;
    }
}
