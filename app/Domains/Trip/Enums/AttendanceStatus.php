<?php

namespace App\Domains\Trip\Enums;

/**
 * Whether somebody actually travelled (Chapter 8's Attendance section).
 *
 * 🔴 This is the enum money is read off, which is why it has a state machine rather than
 * being a free label. Decision D18 lets the DRIVER decide these values, and the Master
 * Plan (§15.6) names that for what it is: "one party deciding the other's bill". A value
 * that could be changed to anything at any time would mean a passenger marked present on
 * Monday could be made a no-show on Friday, after the money had already moved.
 *
 * `pending` is the honest starting point and stays that way unless somebody DECIDES
 * otherwise: it means nobody recorded whether this person travelled, which is not the
 * same as recording that they did not.
 */
enum AttendanceStatus: string
{
    case Pending = 'pending';
    case Present = 'present';
    case Late = 'late';
    case PassengerNoShow = 'passenger_no_show';
    case DriverNoShow = 'driver_no_show';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether this counts as having travelled — what Phase 8 will collect on.
     */
    public function travelled(): bool
    {
        return $this === self::Present || $this === self::Late;
    }

    /**
     * @return list<self>
     */
    private function allowedTransitions(): array
    {
        return match ($this) {
            /*
             * From `pending`, anything may be decided — that is the decision being made.
             */
            self::Pending => [
                self::Present, self::Late, self::PassengerNoShow,
                self::DriverNoShow, self::Cancelled,
            ],

            /*
             * 🔒 Present and late are FINAL, and that is the safeguard. Once a driver has
             * said somebody was in the car, she cannot take it back — otherwise a
             * passenger charged on Monday could be recorded absent on Friday, and the
             * dispute window would protect nothing. A mistake here is corrected by the
             * passenger disputing it and support overturning it, which leaves a record of
             * who changed what.
             *
             * The same for a no-show: it is held against somebody, so unwinding it is a
             * reviewer's job, not the driver's.
             */
            default => [],
        };
    }
}
