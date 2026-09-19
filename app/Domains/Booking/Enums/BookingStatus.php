<?php

namespace App\Domains\Booking\Enums;

/**
 * The state machine from Bible §3.6 / ERD §9 — every transition must go
 * through canTransitionTo() so a cancelled trip can never silently become
 * "completed".
 */
enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case CancelledByPassenger = 'cancelled_by_passenger';
    case CancelledByDriver = 'cancelled_by_driver';
    case Expired = 'expired';
    case NoShow = 'no_show';

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
            self::Pending => [self::Confirmed, self::Expired, self::CancelledByPassenger],
            self::Confirmed => [
                self::Completed, self::NoShow, self::CancelledByPassenger, self::CancelledByDriver,
            ],
            default => [],
        };
    }
}
