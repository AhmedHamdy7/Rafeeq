<?php

namespace App\Domains\Reliability\Enums;

use App\Domains\Commute\Support\ScheduleLock;
use App\Domains\Reliability\Support\ReliabilitySettings;

/**
 * Something that happened which bears on whether somebody can be relied on (Feature 03 v5).
 *
 * > *"Cancellation/displacement reduces Reliability Score even when fee paid."* · *"Admin monthly
 * > reviews low Reliability/repeat violations for drivers and passengers."*
 *
 * 🔴 Only kinds the system can actually observe are here. v5 names more consequences than the code
 * can currently witness — a driver who never starts, for one — and an enum case for an event
 * nothing emits would read as a measured thing that is permanently zero. Those arrive with the
 * features that produce them, which is how {@see self::DriverAbandonedActiveTrip} arrived.
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
     * person — ten women rearranging their mornings is ten times the harm of one. Weighed on the
     * same notice ladder as a targeted cancellation (Feature 03 files X-3 has one "driver cancels"
     * row); see {@see self::weight()}.
     */
    case DriverDisplacedPassenger = 'driver_displaced_passenger';

    /** She called off a whole day that people had booked. */
    case DriverCancelledDay = 'driver_cancelled_day';

    /**
     * She stopped a run that had already set off (Feature 03 v5 §125's Cancel Active Trip).
     *
     * 🔴 Weighed as X-3's "ends a trip for other reason" (−8), the second heaviest after a
     * no-show, and the reason is where it leaves people rather than how deliberate it was. Every other cancellation here happens while a passenger is still at home
     * with her phone: she has lost a lift and has to find another. This one can end with a woman on
     * the side of a road she did not choose, part-way to work, with no lift from there — and v5
     * treats that as its own act, with its own screen and its own emergency appeal.
     *
     * Recorded per affected passenger, like a displacement and for the same reason, and that
     * includes the ones who were never collected: their morning is ruined either way.
     */
    case DriverAbandonedActiveTrip = 'driver_abandoned_active_trip';

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
     *
     * Weighed as the DRIVER NO-SHOW row of X-3 (−10): whether the morning expired at +20 or she
     * gave up first, from where she stood the car never came.
     */
    case DriverLateCausedExit = 'driver_late_caused_exit';

    /**
     * She still had not set off when the morning reached its violation rung (+10 minutes), with
     * people booked on it (Feature 03 files X-3: "lateness −2").
     *
     * Once per morning, not per passenger: lateness is one act, and the per-passenger harm of a
     * morning that never happens at all is carried by {@see self::DriverLateCausedExit}. Recorded
     * by the delay sweep, which stamps the rung so a re-run cannot record it twice.
     */
    case DriverLate = 'driver_late';

    /** She was recorded as not having travelled, by the driver who was there. */
    case PassengerNoShow = 'passenger_no_show';

    /**
     * She kept the car waiting and then boarded — her attendance is LATE (X-3: "lateness −1").
     *
     * The same moment that starts her late-boarding warning; the warning is the sentence she is
     * told, this is the line in the record the monthly review reads.
     */
    case PassengerLate = 'passenger_late';

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
            self::DriverAbandonedActiveTrip,
            self::DriverLateCausedExit,
            self::DriverLate => ReliabilityRole::Driver,

            self::PassengerNoShow,
            self::PassengerCancelledLate,
            self::PassengerLate => ReliabilityRole::Passenger,
        };
    }

    /**
     * Whether the weight depends on how much notice was given (the cancellation rows of X-3).
     */
    public function dependsOnNotice(): bool
    {
        return match ($this) {
            self::DriverCancelledBooking,
            self::DriverDisplacedPassenger,
            self::DriverCancelledDay,
            self::PassengerCancelledLate => true,
            default => false,
        };
    }

    /**
     * How many points this costs (Feature 03 files X-3, decisions D3/D4) — an admin setting.
     *
     * 🔴 For a cancellation the notice decides: `$hoursNotice` is the time from the event to the
     * day's departure. Fewer than `late_cancellation_hours` (10) is short notice; up to the
     * schedule lock (16) is mid; beyond it is long. No notice known — a day with no departure time
     * — is weighed as short notice, the side that does not flatter the person who cancelled.
     *
     * A displacement (a paused commute, a replaced vehicle) and a called-off day are cancellations
     * by the driver and take the same ladder: the table has one row for a driver cancelling, and
     * from the passenger's kerb the three are the same morning lost.
     *
     * A passenger's cancellation has only one weight: the table gives her 10–16h "—" and ≥ 16h 0,
     * and the callers record nothing outside the short-notice window at all.
     *
     * The weight is SNAPSHOTTED onto each event when it is recorded, so changing these numbers
     * never rewrites what somebody's record already said.
     */
    public function weight(?float $hoursNotice = null): int
    {
        return match ($this) {
            self::DriverLateCausedExit => ReliabilitySettings::driverNoShow(),
            self::DriverAbandonedActiveTrip => ReliabilitySettings::driverEndedTrip(),
            self::DriverLate => ReliabilitySettings::driverLate(),
            self::PassengerNoShow => ReliabilitySettings::passengerNoShow(),
            self::PassengerLate => ReliabilitySettings::passengerLate(),
            self::PassengerCancelledLate => ReliabilitySettings::passengerCancelShortNotice(),

            self::DriverCancelledBooking,
            self::DriverDisplacedPassenger,
            self::DriverCancelledDay => match (true) {
                $hoursNotice === null,
                $hoursNotice < ReliabilitySettings::lateCancellationHours() => ReliabilitySettings::driverCancelShortNotice(),
                $hoursNotice < ScheduleLock::hours() => ReliabilitySettings::driverCancelMidNotice(),
                default => ReliabilitySettings::driverCancelLongNotice(),
            },
        };
    }
}
