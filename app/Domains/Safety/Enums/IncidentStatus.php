<?php

namespace App\Domains\Safety\Enums;

/**
 * Where a report stands, as the operations team moves it along (Chapter 12 §Incident
 * Moderation).
 *
 * The sequence is short on purpose:
 *
 *   open → under_review → resolved | closed → appealed → (under_review | escalated | resolved | closed)
 *            ↘ escalated ↗
 *
 * - `under_review` means a named person has it. Not "somebody looked at it" — the
 *   assignment and this state change together, so a report can never be "being
 *   reviewed" by nobody.
 * - `escalated` is the state for "beyond what this desk decides alone": police liaison,
 *   legal, a lead. It is still open.
 * - `resolved` means something was done; `closed` means it ended without action (lost
 *   item never found, a report that could not be substantiated). Both are final, and
 *   the reporter is told which.
 */
enum IncidentStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * The reporter appealed the decision and staff are looking again (HISTORY-10; D-0021). An OPEN
     * state — back in the queue, unassigned, with a fresh deadline — because a case somebody is
     * waiting on an answer for is a case, whatever was decided before.
     */
    case Appealed = 'appealed';

    /**
     * The states a report is still being worked in. One list, because the queue, the dashboard's
     * count, a member's open reports and the evidence rule all ask it — and four copies of it are
     * how an appealed report would have silently dropped out of the queue.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Open, self::UnderReview, self::Escalated, self::Appealed];
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        $values = [];

        foreach (self::open() as $status) {
            $values[] = $status->value;
        }

        return $values;
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this === self::Resolved || $this === self::Closed;
    }

    /**
     * @return list<self>
     */
    private function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::UnderReview, self::Escalated, self::Resolved, self::Closed],
            self::UnderReview => [self::Escalated, self::Resolved, self::Closed],
            /*
             * No way back to `under_review`. An escalated case that the lead hands back is
             * still a case somebody escalated, and the status is what the queue sorts on —
             * stepping it down would bury it among the routine ones.
             */
            self::Escalated => [self::Resolved, self::Closed],
            /*
             * A decision may be appealed (HISTORY-10). Only the reporter's appeal moves it here —
             * staff never reopen a case into "appealed" — and IncidentAppeals decides whether she
             * still may. From there it is worked like a new case.
             */
            self::Resolved, self::Closed => [self::Appealed],
            self::Appealed => [self::UnderReview, self::Escalated, self::Resolved, self::Closed],
        };
    }
}
