<?php

namespace App\Domains\Verification\Support;

use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Verification\Contracts\VirusScanner;
use Illuminate\Http\UploadedFile;

/**
 * The one place an uploaded file becomes a stored document.
 *
 * The order is the security design, not an implementation detail:
 *
 *   1. scan the ORIGINAL bytes — the thing the person actually sent;
 *   2. re-encode to strip metadata and any appended payload;
 *   3. only then write to the private disk.
 *
 * Scanning after re-encoding would scan a file we produced ourselves, which
 * proves nothing about the upload. Writing before scanning would put unscanned
 * bytes in the bucket, and "we deleted it afterwards" is not the same as never
 * having stored it.
 *
 * It lives here, shared, because identity documents and vehicle documents both
 * need it — and a second copy of a three-step order is a second copy that can
 * quietly lose a step.
 */
final readonly class DocumentIntake
{
    public function __construct(
        private VirusScanner $scanner,
        private ImageSanitiser $sanitiser,
        private DocumentStorage $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $auditContext  extra detail for the security
     *                                              event written when a file is refused — never the bytes
     * @return array{path: string, hash: string, mime: string, size: int}
     */
    public function accept(User $owner, UploadedFile $file, array $auditContext = []): array
    {
        $original = (string) file_get_contents($file->getRealPath());

        if (! $this->scanner->isClean($original, (string) $file->getClientOriginalName())) {
            SecurityLog::record(SecurityEventType::DocumentRejected, $owner, metadata: [
                ...$auditContext,
                // The hash, never the bytes and never the filename the uploader
                // chose: enough to recognise the same file arriving again,
                // useless to anyone reading the audit trail.
                'sha256' => hash('sha256', $original),
            ]);

            throw DomainException::of(ErrorCode::DocumentRejectedByScanner);
        }

        $sanitised = $this->sanitiser->sanitise($original);

        $stored = $this->storage->put($owner, $sanitised['contents']);

        return [
            'path' => $stored['path'],
            'hash' => $stored['hash'],
            'mime' => $sanitised['mime'],
            'size' => strlen($sanitised['contents']),
        ];
    }
}
