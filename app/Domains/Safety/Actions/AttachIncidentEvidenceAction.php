<?php

namespace App\Domains\Safety\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\EvidenceKind;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\IncidentEvidence;
use App\Domains\Safety\Support\SafetySettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Verification\Support\DocumentIntake;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Attaching a photograph to a report (Chapter 10, screen 32's "add evidence").
 *
 * 🔴 The file goes through `DocumentIntake` — the same three-step order identity documents use, and
 * for the same reasons. Scan the ORIGINAL bytes, re-encode to strip metadata and any appended
 * payload, and only then write to the private disk. A second upload path would be a second place
 * that can quietly lose a step, and this is the one path where the uploader is often a stranger to
 * us: a report can be filed by an account that has done nothing but sign in.
 *
 * 🔒 Re-encoding matters more here than anywhere else in the product, not less. A photograph taken
 * at the scene of an incident carries the EXIF GPS of **where the person was standing when they were
 * frightened**, and a report is read by staff and may travel further than that. The person
 * photographed a car; they did not agree to hand over their position. Pitfall #24, and this is its
 * sharpest case.
 *
 * 🔒 The path is never returned to anybody (pitfall #23). The reporter knows what they sent; the
 * reviewer reads it through the dashboard. No endpoint on the mobile API hands back a file, a URL or
 * a path, so there is nothing to forward and nothing to guess at.
 *
 * ---
 *
 * Two bounds, both deliberate:
 *
 * - **Only while the case is live.** A file landing on a closed case is a file nobody is going to
 *   read, and answering "accepted" to it is worse than refusing: the person believes somebody has
 *   it. Refusing with `INCIDENT_CLOSED` sends them to file a new report, which puts a human back in
 *   front of it. The status itself is NOT returned in the error — see the controller.
 * - **Capped per report**, because `incident_evidence` is never deleted. Anything written there is
 *   written for the whole retention period.
 */
final readonly class AttachIncidentEvidenceAction
{
    public function __construct(private DocumentIntake $intake) {}

    public function execute(User $reporter, Incident $incident, UploadedFile $file, EvidenceKind $kind): IncidentEvidence
    {
        if (! $incident->isOpen()) {
            throw DomainException::of(ErrorCode::IncidentClosed);
        }

        $limit = SafetySettings::maxEvidencePerIncident();

        /*
         * Counted here rather than trusted from a loaded relation: two uploads in flight at once
         * would both see the same stale count, and the cap exists to bound storage rather than to
         * be exact to the file. Close enough is the honest standard for this one, and refusing a
         * real report's sixth photograph is the failure that actually costs something.
         */
        if ($incident->evidence()->count() >= $limit) {
            throw DomainException::of(ErrorCode::IncidentEvidenceLimitReached, fields: [
                'limit' => [(string) $limit],
            ]);
        }

        $stored = $this->intake->accept($reporter, $file, auditContext: [
            // Enough for somebody reading the security log to know which report a refused upload
            // was aimed at. Never the description and never the category: see SafetyEventLog.
            'incidentId' => $incident->id,
        ]);

        return DB::transaction(function () use ($incident, $stored, $kind): IncidentEvidence {
            $evidence = new IncidentEvidence;

            $evidence->fill([
                'incident_id' => $incident->id,
                'file_path' => $stored['path'],
                'kind' => $kind->value,
                /*
                 * 🔒 Chain of custody. The hash is what lets anybody — a reviewer, a court, us —
                 * say that the file being looked at is the file that was sent, which is the whole
                 * reason this row exists rather than just the file.
                 */
                'file_hash' => $stored['hash'],
                /*
                 * When it may be destroyed. Written now rather than computed on read, so changing
                 * the retention policy cannot silently extend the life of a photograph somebody
                 * sent under the policy that was in force when they sent it.
                 */
                'purge_after' => now()->addDays(SafetySettings::evidenceRetentionDays())->toDateString(),
            ]);

            $evidence->save();

            /*
             * Deliberately NO safety event. The report's own creation is already in the record that
             * is never deleted; a second row per attached photograph would add volume to the table
             * operators read without adding a fact — the evidence row IS the record that a file
             * arrived, and it is itself undeletable.
             */

            return $evidence;
        });
    }
}
