<?php

namespace App\Domains\Verification\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Verification\Models\IdentityDocument;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Where identity documents live and how anyone is allowed to look at one.
 *
 * Decision D7: a private disk, never a public URL. Two things follow from
 * that and are enforced here rather than left to each caller.
 *
 * The stored path carries no meaning. It is a random name under the owner's
 * id — not the document kind, not the person's name, not the original
 * filename. A bucket listing then reveals nothing about what any file
 * contains, and a leaked path cannot be adjusted to guess at a neighbour.
 *
 * Access is always time-limited. On a disk that can presign (S3) the link
 * goes straight to storage; on a local disk it goes through a signed app
 * route. Both expire in minutes, because the link is the only thing standing
 * between a forwarded URL and someone's national ID.
 */
final class DocumentStorage
{
    /**
     * `FilesystemAdapter`, not the `Filesystem` contract: the contract omits
     * `temporaryUrl()` and `response()`, both of which this class and the
     * controller need, and the adapter is what Laravel actually hands back for
     * a local disk and for S3 alike.
     */
    public function disk(): FilesystemAdapter
    {
        return Storage::disk(config('rafeeq.verification.documents_disk'));
    }

    /**
     * The document kind is deliberately NOT part of the path — see the class
     * note on why a stored name must mean nothing.
     *
     * @return array{path: string, hash: string}
     */
    public function put(User $owner, string $contents): array
    {
        // Partitioned by owner so a GDPR-style "delete everything about me"
        // is one prefix, not a query. The filename itself is opaque.
        $path = sprintf('users/%s/%s.jpg', $owner->id, Str::ulid());

        $this->disk()->put($path, $contents);

        return [
            'path' => $path,
            // Lets a duplicate submission be spotted without decrypting or
            // re-reading the file (Chapter 3 §14 fraud checks).
            'hash' => hash('sha256', $contents),
        ];
    }

    public function delete(IdentityDocument $document): void
    {
        $this->disk()->delete($document->file_path);
    }

    /**
     * A link the holder can use for the next couple of minutes and nobody can
     * extend.
     */
    public function temporaryUrl(IdentityDocument $document): string
    {
        $expiresAt = now()->addSeconds((int) config('rafeeq.verification.document_url_ttl_seconds'));

        // Configuration, not a capability check on the disk: see the note on
        // `presigned_document_urls` in config/rafeeq.php for why asking the
        // disk what it supports takes a different branch under test than in
        // production, which is the one place these two must agree.
        if (config('rafeeq.verification.presigned_document_urls')) {
            return $this->disk()->temporaryUrl($document->file_path, $expiresAt);
        }

        // A signed route that still checks ownership when it is called. The
        // signature bounds the lifetime; the route bounds who it works for.
        return URL::temporarySignedRoute(
            'verification.documents.show',
            $expiresAt,
            ['document' => $document->id],
        );
    }
}
