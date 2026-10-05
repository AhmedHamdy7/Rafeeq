<?php

namespace App\Domains\Shared\Actions;

use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\IncidentEvidence;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Models\IdentityDocument;
use App\Domains\Verification\Support\DocumentStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Destroys private files past their `purge_after` date (ERD §18; Bible: "`purge_after` is enforced
 * by a daily job — not a promise on paper").
 *
 * 🔴 Until this existed every one of those dates was a promise on paper. The member is even SHOWN
 * one — `IncidentEvidenceResource::purgeAfter` answers "how long do you keep this photograph" — and
 * nothing ever acted on it.
 *
 * The bytes go; the row stays with `purged_at`. The row is the record that a file existed and, for
 * evidence, the hash that proves what it held — a court asking "what was attached to this report"
 * gets an answer even after the picture is gone. Bytes first, then the mark: a run that dies in
 * between leaves a row whose file is already missing, and the next run finishes it harmlessly.
 *
 * 🔒 Two holds, both about a decision still being made with the file:
 *
 * - **A document under review is never purged** (verification pending or waiting on the member,
 *   vehicle document pending). Deleting the licence a reviewer is about to open turns a privacy
 *   rule into a reason the member is rejected.
 * - **Evidence on a case that is still open is never purged.** A report escalated to the police
 *   when its retention date passes is exactly the report whose photographs must still exist. It is
 *   destroyed on the first night after the case closes instead.
 */
final readonly class PurgeExpiredFilesAction
{
    public function __construct(private DocumentStorage $storage) {}

    /**
     * @return array{documents: int, vehicleDocuments: int, evidence: int}
     */
    public function execute(): array
    {
        return [
            'documents' => $this->purge(IdentityDocument::query()->whereHas('verification', fn (Builder $verification) => $verification
                ->whereNotIn('status', [VerificationStatus::Pending->value, VerificationStatus::ActionNeeded->value]))),

            'vehicleDocuments' => $this->purge(VehicleDocument::query()
                ->where('verification_status', '!=', VehicleVerificationStatus::Pending->value)),

            'evidence' => $this->purge(IncidentEvidence::query()->whereHas('incident', fn (Builder $incident) => $incident
                ->whereIn('status', [IncidentStatus::Resolved->value, IncidentStatus::Closed->value]))),
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function purge(Builder $query): int
    {
        $purged = 0;

        $query
            ->whereNull('purged_at')
            ->whereDate('purge_after', '<=', now()->toDateString())
            ->chunkById(200, function ($files) use (&$purged): void {
                foreach ($files as $file) {
                    $this->storage->disk()->delete($file->file_path);

                    $file->forceFill(['purged_at' => now()])->save();

                    $purged++;
                }
            });

        return $purged;
    }
}
