<?php

namespace App\Domains\Safety\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\IncidentAppeal;
use App\Domains\Safety\Support\IncidentAppeals;
use App\Domains\Safety\Support\SafetyEventLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * "I don't accept this decision" on a resolved or closed report (HISTORY-10; D-0021, SAFE-11).
 *
 * One appeal, and a second only with new evidence, each within 48 hours of the decision, then
 * final — see IncidentAppeals for the order the rule is checked in. A refusal answers like the
 * platform's other appeals do: `422 VALIDATION_FAILED` with the reason in `error.fields.appeal`.
 *
 * 🔴 The case goes back to the queue as a case: `appealed`, UNASSIGNED, and with a fresh deadline on
 * its own severity's scale. Left with the reviewer who decided it, an appeal is a request to the
 * same person to agree they were wrong; left with its old deadline, it would sit at the top of the
 * queue as overdue from the moment it arrived. Whoever takes it next decides again.
 *
 * 🔒 The reporter's own report only — the controller finds it scoped to her, and a report is never
 * shown to the person it is about, so the reported side has no report to appeal here. What that
 * person can appeal is what was done to them: the fee or the warning, on their own endpoints.
 */
final readonly class AppealIncidentAction
{
    public function execute(User $reporter, Incident $incident, string $reason): Incident
    {
        return DB::transaction(function () use ($reporter, $incident, $reason): Incident {
            // Locked: two taps on Appeal must not become rounds one and two.
            $incident = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $incident->loadCount('evidence')->load('appeals');

            $refusal = IncidentAppeals::refusal($incident);

            if ($refusal !== null) {
                throw DomainException::of(ErrorCode::ValidationFailed, fields: ['appeal' => [$refusal]]);
            }

            $appeal = new IncidentAppeal;
            $appeal->incident_id = $incident->id;
            $appeal->user_id = $reporter->id;
            $appeal->round = IncidentAppeals::made($incident) + 1;
            $appeal->reason = $reason;
            $appeal->evidence_count = IncidentAppeals::evidenceCount($incident);
            $appeal->save();

            $incident->status = IncidentStatus::Appealed;
            $incident->assigned_admin_id = null;
            $incident->sla_due_at = now()->addHours(ReportIncidentAction::slaHoursFor($incident->severity));
            $incident->save();

            // Into the record that is never deleted, like the report itself — the round, never the
            // words (see SafetyEventLog on what belongs in a table nobody may delete).
            SafetyEventLog::record(
                type: SafetyEventType::IncidentAppealed,
                user: $reporter,
                severity: $incident->severity,
                metadata: ['incidentId' => $incident->id, 'round' => $appeal->round],
            );

            return $incident->setRelation('appeals', $incident->appeals->push($appeal));
        });
    }
}
