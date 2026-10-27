<?php

namespace App\Domains\Commute\Enums;

enum ScheduledTripStatus: string
{
    case Scheduled = 'scheduled';
    case Preparing = 'preparing';
    case EnRoute = 'en_route';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * The morning came and went and nobody drove it (Feature 03 v5 §240: *"+20m auto-EXPIRED only
     * if never started"*; §160 lists `EXPIRED` among an occurrence's states).
     *
     * 🔴 Distinct from `Cancelled`, which somebody decided. Nobody decided this: the day simply
     * did not happen, and the difference is what a driver's record and a passenger's history each
     * need to say. A cancellation is an act with a time and an actor; an expiry is an absence.
     *
     * Reachable only from `Scheduled` — a day that started is a day that happened, however late,
     * and v5 is explicit that starting at +12 leaves it in progress.
     */
    case Expired = 'expired';

    /** The state machine documented in ERD §7. */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * @return list<self>
     */
    private function allowedTransitions(): array
    {
        return match ($this) {
            // 🔴 `Expired` only from here: a day that set off is a day that happened, however late.
            self::Scheduled => [self::Preparing, self::Cancelled, self::Expired],
            self::Preparing => [self::EnRoute, self::Cancelled],
            self::EnRoute => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed],
            default => [],
        };
    }
}
