<?php

namespace App\Domains\Reliability\Enums;

/**
 * Something that happened which bears on whether somebody can be relied on (Feature 03 v5).
 *
 * > *"Cancellation/displacement reduces Reliability Score even when fee paid."* · *"Admin monthly
 * > reviews low Reliability/repeat violations for drivers and passengers."*
 *
 * 🔴 Only kinds the system can actually observe are here. v5 names more consequences than the code
 * can currently witness — a driver who never starts, an active trip cancelled mid-run — and an enum
 * case for an event nothing emits would read as a measured thing that is permanently zero. Those
 * arrive with the features that produce them.
 *
 * 🔴 Each case says whose record it lands on, because one account is one person doing two things.
 * A driver's cancellations must not reach her passenger standing: a woman who drives badly and
 * rides impeccably has two different records, and merging them would have a future driver refuse
 * her for something she did at the wheel.
 */
enum ReliabilityEventKind: string
{
    /**
     * She cancelled one passenger's confirmed seat.
     *
     * The most pointed of these: she looked at one person's booking and ended it. v5 attaches both
     * a fee and a reliability impact.
     */
    case DriverCancelledBooking = 'driver_cancelled_booking';

    /**
     * A seat went because she paused the commute or replaced her vehicle.
     *
     * 🔴 Recorded per displaced passenger rather than once per decision, because the harm is per
     * person — ten women rearranging their mornings is ten times the harm of one. It carries a
     * smaller weight than a targeted cancellation for the same reason: she did not choose any of
     * them individually, and a lapsed licence is not the same act as ending somebody's seat.
     */
    case DriverDisplacedPassenger = 'driver_displaced_passenger';

    /** She called off a whole day that people had booked. */
    case DriverCancelledDay = 'driver_cancelled_day';

    /**
     * She was late enough, or drove past somebody, that a passenger gave up on the journey
     * (Feature 03 v5 §241's driver-fault exit).
     *
     * 🔴 The one kind here that is recorded by somebody ELSE pressing a button — a woman leaving a
     * journey the car never came for. It lands on the driver because that is whose failure it was,
     * and nothing lands on the passenger, which is the whole point of the status the exit writes.
     *
     * Recorded per passenger left behind, like a displacement and for the same reason: three women
     * who gave up on one morning is three mornings wasted.
     */
    case DriverLateCausedExit = 'driver_late_caused_exit';

    /** She was recorded as not having travelled, by the driver who was there. */
    case PassengerNoShow = 'passenger_no_show';

    /**
     * She cancelled her seat close enough to departure that the driver could not fill it.
     *
     * ⚠️ "Late" is measured against departure, not against when she booked. Cancelling a week out
     * costs nobody anything and is recorded as nothing at all.
     */
    case PassengerCancelledLate = 'passenger_cancelled_late';

    /**
     * Whether this is about how she drives or how she rides.
     */
    public function role(): ReliabilityRole
    {
        return match ($this) {
            self::DriverCancelledBooking,
            self::DriverDisplacedPassenger,
            self::DriverCancelledDay,
            self::DriverLateCausedExit => ReliabilityRole::Driver,

            self::PassengerNoShow,
            self::PassengerCancelledLate => ReliabilityRole::Passenger,
        };
    }

    /**
     * How many points this costs, from config.
     *
     * 🔴 Config rather than code, and the defaults are **placeholders pending a product decision**
     * — exactly as `cancellation_fee_piastres` is. v5 defers the fee brackets outright ("exact
     * brackets/amounts are not decided") and says nothing about weights, so these numbers are a
     * working shape rather than a policy anybody has agreed. They are proportionate to each other,
     * which is the part that is defensible: a targeted cancellation costs more than being swept up
     * in a pause, and a no-show costs a passenger about what a cancellation costs a driver.
     *
     * The weight is SNAPSHOTTED onto each event when it is recorded, so changing these numbers
     * never rewrites what somebody's record already said.
     */
    public function weight(): int
    {
        return (int) config("rafeeq.reliability.weights.{$this->value}");
    }
}
