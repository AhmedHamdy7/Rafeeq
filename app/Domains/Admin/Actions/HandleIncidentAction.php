<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Support\SafetyEventLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * The safety desk working a report (Chapter 12 §Incident Moderation).
 *
 * 🔴 `resolution` is NOT an internal note. `IncidentResource` returns it to the REPORTER,
 * verbatim, as the platform's answer to what they reported — so the text an operator types
 * when resolving or closing is a message to somebody who may have been harassed, and the
 * dashboard asks for it in those words. The internal reasoning for an escalation goes to
 * `admin_actions.reason`, which the member never sees.
 *
 * What is deliberately NOT here: warning, suspending or banning the reported person
 * (Chapter 12 lists all three). Suspension is its own action with its own case number and
 * appeal window, it changes somebody's access to the platform, and it lands with
 * MEMBERS. Folding it into "resolve" would make the heaviest thing staff can do to a member
 * a side effect of closing a ticket.
 *
 * Every step re-reads the case under a lock — see `RespondToSosAction::locked()` for why.
 */
final readonly class HandleIncidentAction
{
    /**
     * "I've got this one." Assigns the case to the operator and, if nobody had started on
     * it, moves it to `under_review`.
     *
     * Taking over a colleague's case is allowed and recorded with who had it before. A case
     * that only its original assignee may touch is a case that waits for them to come back
     * from leave.
     */
    public function take(Incident $incident, AdminUser $admin): Incident
    {
        return DB::transaction(function () use ($incident, $admin): Incident {
            $incident = $this->locked($incident);

            $this->assertNotFinal($incident);

            if ($incident->assigned_admin_id === $admin->id) {
                return $incident;
            }

            $before = $this->snapshot($incident);

            $incident->assigned_admin_id = $admin->id;

            // Escalated stays escalated: changing who holds it is not a step down.
            if ($incident->status === IncidentStatus::Open) {
                $incident->status = IncidentStatus::UnderReview;
            }

            $incident->save();

            AdminActionLog::record($admin, 'incident.assign', $incident, $before, $this->snapshot($incident));

            return $incident;
        });
    }

    /**
     * Beyond this desk: police liaison, legal, a lead. The reason is internal.
     */
    public function escalate(Incident $incident, AdminUser $admin, string $reason): Incident
    {
        return $this->move($incident, $admin, IncidentStatus::Escalated, 'incident.escalate', $reason);
    }

    /**
     * Something was done. `$messageToReporter` is what they will read.
     */
    public function resolve(Incident $incident, AdminUser $admin, string $messageToReporter): Incident
    {
        return $this->move($incident, $admin, IncidentStatus::Resolved, 'incident.resolve', $messageToReporter, final: true);
    }

    /**
     * It ended without action — nothing found, nothing that could be done. Still an answer
     * the reporter is owed, and `$messageToReporter` is it.
     */
    public function close(Incident $incident, AdminUser $admin, string $messageToReporter): Incident
    {
        return $this->move($incident, $admin, IncidentStatus::Closed, 'incident.close', $messageToReporter, final: true);
    }

    private function move(
        Incident $incident,
        AdminUser $admin,
        IncidentStatus $to,
        string $auditAction,
        string $text,
        bool $final = false,
    ): Incident {
        return DB::transaction(function () use ($incident, $admin, $to, $auditAction, $text, $final): Incident {
            $incident = $this->locked($incident);

            $this->assertNotFinal($incident);

            if (! $incident->status->canTransitionTo($to)) {
                throw DomainException::of(ErrorCode::IncidentTransitionNotAllowed);
            }

            $before = $this->snapshot($incident);

            $incident->status = $to;
            // Whoever moves an unassigned case owns it from here on: a case acted on by
            // nobody in particular is how the follow-up question goes unanswered.
            $incident->assigned_admin_id ??= $admin->id;

            if ($final) {
                $incident->resolution = $text;
                $incident->resolved_at = now();
            }

            $incident->save();

            AdminActionLog::record($admin, $auditAction, $incident, $before, $this->snapshot($incident), reason: $text);

            SafetyEventLog::record(
                type: SafetyEventType::AdminIntervention,
                user: $incident->reporter,
                severity: $incident->severity,
                tripSession: $incident->tripSession,
                booking: $incident->booking,
                // The status, never the text: see SafetyEventLog on what belongs in a table
                // that is never deleted.
                metadata: ['incidentId' => $incident->id, 'status' => $to->value],
            );

            // The reporter hears about an answer, not about an escalation — that is internal.
            if ($final) {
                Notifier::send($incident->reporter,
                    $to === IncidentStatus::Resolved ? NotificationType::ReportResolved : NotificationType::ReportClosed,
                    data: ['incidentId' => $incident->id],
                );
            }

            return $incident;
        });
    }

    private function locked(Incident $incident): Incident
    {
        return Incident::query()
            ->whereKey($incident->getKey())
            ->lockForUpdate()
            ->with('reporter', 'tripSession', 'booking')
            ->firstOrFail();
    }

    /**
     * A finished case is answered with the same code the reporter's app gets when it tries
     * to add evidence to one: it is closed, and the move is a new report.
     */
    private function assertNotFinal(Incident $incident): void
    {
        if ($incident->status->isFinal()) {
            throw DomainException::of(ErrorCode::IncidentClosed);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(Incident $incident): array
    {
        return [
            'status' => $incident->status->value,
            'assigned_admin_id' => $incident->assigned_admin_id,
        ];
    }
}
