<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Safety\Models\IncidentEvidence;
use App\Domains\Verification\Models\IdentityDocument;
use App\Domains\Verification\Support\DocumentStorage;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff opening a private file: an identity document in the verification queue, or a
 * photograph attached to a report.
 *
 * 🔴 Until this existed, `verification.view_document` was a permission nothing used: the queue
 * listed "National id front — Uploaded" and a reviewer approved an identity without being able
 * to see it. The evidence on a report was the same — counted, never openable.
 *
 * Every rule here is about the file being the most sensitive thing on the platform:
 *
 * - **Its own permission each**, re-checked on the request: seeing a queue is not opening what is
 *   in it.
 * - **Every opening is audited.** Not because staff are suspects; because "who has looked at her
 *   national ID" is a question the member is entitled to have answered.
 * - **A file the virus scan did not pass is never served**, whoever asks.
 * - **The hash is checked on the way out.** A file whose bytes no longer match the hash recorded
 *   when it arrived is refused rather than shown: an altered piece of evidence presented as the
 *   original is worse than none, and the mismatch itself is what an investigation needs to know.
 * - **No caching anywhere, inline only, no sniffing** — the bytes go to this screen and nowhere
 *   else.
 *
 * Served through the session, under the same auth + MFA + idle-timeout middleware as every page,
 * rather than by a signed URL: a link that works without the session is a link that works when
 * pasted somewhere else.
 */
final class StaffFileController extends Controller
{
    public function document(string $document, DocumentStorage $storage): Response
    {
        $this->authorizeTo(AdminPermission::VerificationViewDocument);

        $file = IdentityDocument::query()->whereKey($document)->firstOrFail();

        abort_unless($file->virus_scan_status->value === 'clean', 404);

        return $this->serve($storage, $file->file_path, $file->file_hash, 'verification.view_document', $file);
    }

    public function evidence(string $evidence, DocumentStorage $storage): Response
    {
        $this->authorizeTo(AdminPermission::SafetyViewEvidence);

        $file = IncidentEvidence::query()->whereKey($evidence)->firstOrFail();

        return $this->serve($storage, $file->file_path, $file->file_hash, 'evidence.view', $file);
    }

    private function serve(DocumentStorage $storage, string $path, ?string $expectedHash, string $auditAction, $subject): Response
    {
        abort_unless($storage->disk()->exists($path), 404);

        $contents = (string) $storage->disk()->get($path);

        // Chain of custody: the file must still be the file that arrived.
        abort_if($expectedHash !== null && ! hash_equals($expectedHash, hash('sha256', $contents)), 409,
            __('admin.files.integrity_failed'));

        AdminActionLog::record(Auth::guard('admin')->user(), $auditAction, $subject);

        return response($contents, 200, [
            // Everything stored here was re-encoded as JPEG on the way in (see DocumentIntake).
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function authorizeTo(AdminPermission $permission): void
    {
        abort_unless(Auth::guard('admin')->user()?->can($permission->value) === true, 403);
    }
}
