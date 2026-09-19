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
            self::Scheduled => [self::Preparing, self::Cancelled],
            self::Preparing => [self::EnRoute, self::Cancelled],
            self::EnRoute => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed],
            default => [],
        };
    }
}
