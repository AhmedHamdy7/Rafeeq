<?php

namespace App\Domains\Safety\Enums;

/**
 * Where a report stands, as the operations team moves it along (Chapter 12 §Incident
 * Moderation).
 *
 * The sequence is short on purpose:
 *
 *   open → under_review → resolved | closed
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
            default => [],
        };
    }
}
