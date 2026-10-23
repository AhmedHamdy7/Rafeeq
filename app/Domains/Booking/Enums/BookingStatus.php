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

    /**
     * She was in the car and got out before her agreed drop-off (Feature 03 v5, which lists
     * `ENDED_EARLY` among a booking's states).
     *
     * 🔴 Neither a completion nor a cancellation, and both of those would be wrong in a way that
     * costs somebody. `completed` would say she was carried to the place she agreed, which decides
     * what she is charged when the money path exists. `cancelled_by_passenger` would say she never
     * travelled and would land on the cancellation history a future driver reads — for a journey she
     * paid for most of, possibly having left it because she felt unsafe.
     *
     * Terminal: the journey is over for her. The run carries on for everybody else.
     */
    case EndedEarly = 'ended_early';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * 🔒 Whether this booking has earned the details that identify a person's car and
     * front door: the EXACT meeting point, and the vehicle's plate.
     *
     * Lives on the enum because two different payloads answer it — the booking itself
     * and the home screen's next-journey card — and a privacy rule written twice is a
     * rule that will one day disagree with itself.
     *
     * A pickup point is often somebody's front door and a plate identifies the car
     * outside it; a pending or cancelled booking is not a relationship that has earned
     * either. Before confirmation the client gets a coarse point and no plate, which
     * is enough to show the right neighbourhood and not enough to wait outside a house.
     */
    public function grantsExactDetails(): bool
    {
        return $this === self::Confirmed
            || $this === self::Completed
            /*
             * Somebody who ended her journey early has already been collected, so withholding the
             * meeting point and the plate from her would protect nothing — she stood at that kerb
             * and got into that car. And she is the one most likely to need the details afterwards,
             * because a safety exit is exactly what a report is written about.
             */
            || $this === self::EndedEarly;
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
                // She was picked up and got out early. Only reachable from confirmed, because a
                // journey has to have started for there to be one to leave.
                self::EndedEarly,
            ],
            default => [],
        };
    }
}
