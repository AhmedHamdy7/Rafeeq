<?php

namespace App\Domains\Reliability\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Reliability\Models\ReliabilityAppealEvidence;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Reliability\Support\ReliabilityAppeals;
use App\Domains\Safety\Enums\EvidenceKind;
use App\Domains\Safety\Support\SafetySettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Verification\Support\DocumentIntake;
use Illuminate\Http\UploadedFile;

final readonly class AttachAppealEvidenceAction
{
    public function __construct(private DocumentIntake $intake) {}

    public function execute(User $member, ReliabilityEvent $event, UploadedFile $file, EvidenceKind $kind): ReliabilityAppealEvidence
    {
        if ($event->user_id !== $member->id) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        if (! ReliabilityAppeals::acceptsEvidence($event)) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'appeal' => [ReliabilityAppeals::refusal($event) ?? 'APPEAL_WINDOW_CLOSED'],
            ]);
        }

        $limit = SafetySettings::maxEvidencePerIncident();

        if ($event->evidence()->count() >= $limit) {
            throw DomainException::of(ErrorCode::IncidentEvidenceLimitReached, fields: [
                'limit' => [(string) $limit],
            ]);
        }

        $stored = $this->intake->accept($member, $file, auditContext: [
            'reliabilityEventId' => (string) $event->getKey(),
        ]);

        $evidence = new ReliabilityAppealEvidence;

        $evidence->fill([
            'reliability_event_id' => $event->getKey(),
            'file_path' => $stored['path'],
            'kind' => $kind->value,
            'file_hash' => $stored['hash'],
            'purge_after' => now()->addDays(SafetySettings::evidenceRetentionDays())->toDateString(),
        ]);

        $evidence->save();

        return $evidence;
    }
}
