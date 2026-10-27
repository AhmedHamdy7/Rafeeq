<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Support\ReliabilityLedger;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Models\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The driver-not-started ladder (Feature 03 v5 §240).
 *
 * > *"Driver not started: +1m reminder; +5m passenger warning; +10m violation/escalation; +20m
 * > auto-EXPIRED only if never started. If started at +12m, remains IN_PROGRESS; existing violation
 * > remains."*
 *
 * 🔴 **Only days that never started are ever in reach of this**, which is what makes the last
 * clause of v5's sentence need no code: a run that sets off at +12 leaves the sweep's query, and
 * nothing here clears a stamp, so the violation it already earned stays. Both halves fall out of
 * the shape rather than being enforced by a rule somebody has to remember.
 *
 * 🔴 **Each rung fires once, recorded in a column.** The sweep runs every minute, so "already
 * warned" has to be a fact rather than a calculation — and calculating it from the inbox would
 * break in ninety days, when retention prunes the row and the sweep warns everybody again about a
 * morning from last season.
 *
 * ⚠️ **No reliability event at the violation rung, and that is a decision rather than an omission.**
 * The score measures harm to people; harm here is per passenger, and the per-booking events at
 * expiry carry it. A flat penalty at +10 would charge a driver for not driving a Thursday nobody had
 * booked — and would charge four penalties for one failure on a day with three riders. Whether she
 * was late at all is already measured, separately, by her on-time rate.
 */
final readonly class SweepDelayedTripsAction
{
    /**
     * Walk every day that should have left and has not.
     *
     * @return array{reminded: int, warned: int, violations: int, expired: int}
     */
    public function sweep(): array
    {
        $counts = ['reminded' => 0, 'warned' => 0, 'violations' => 0, 'expired' => 0];

        $now = CarbonImmutable::now();

        /*
         * 🔴 `Scheduled` is the whole filter for "never started" — the status moves off it the
         * moment a run is prepared or under way. A day that is cancelled, completed or already
         * expired is not here either, which is what makes every rung below safe to apply blindly.
         *
         * Bounded below as well as above: a day from last month that nothing ever swept (a cron
         * that was off, a deploy gap) should be expired quietly rather than generating reminders
         * and warnings about a morning three weeks gone. The lower bound is the expiry rung, so
         * anything older goes straight to the end of the ladder.
         */
        $due = ScheduledTrip::query()
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->whereNotNull('departure_at')
            ->where('departure_at', '<=', $now->subMinutes(self::reminderMinutes()))
            ->with('commuteOffer.driverProfile.user')
            ->orderBy('departure_at')
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($due as $trip) {
            $lateBy = $trip->departure_at->diffInMinutes($now);

            if ($lateBy >= self::expiryMinutes()) {
                $counts['expired'] += $this->expire($trip) ? 1 : 0;

                // Nothing below matters on a morning that is over.
                continue;
            }

            if ($lateBy >= self::violationMinutes() && $trip->delay_violation_at === null) {
                $trip->forceFill(['delay_violation_at' => $now])->save();
                $counts['violations']++;
            }

            if ($lateBy >= self::warningMinutes() && $trip->delay_passengers_warned_at === null) {
                $this->warnPassengers($trip);
                $trip->forceFill(['delay_passengers_warned_at' => $now])->save();
                $counts['warned']++;
            }

            if ($lateBy >= self::reminderMinutes() && $trip->delay_driver_reminded_at === null) {
                $this->remindDriver($trip);
                $trip->forceFill(['delay_driver_reminded_at' => $now])->save();
                $counts['reminded']++;
            }
        }

        return $counts;
    }

    public static function reminderMinutes(): int
    {
        return (int) config('rafeeq.trip.delay_driver_reminder_minutes');
    }

    public static function warningMinutes(): int
    {
        return (int) config('rafeeq.trip.delay_passenger_warning_minutes');
    }

    public static function violationMinutes(): int
    {
        return (int) config('rafeeq.trip.delay_violation_minutes');
    }

    public static function expiryMinutes(): int
    {
        return (int) config('rafeeq.trip.delay_expiry_minutes');
    }

    /**
     * The morning is written off.
     *
     * 🔴 Every still-confirmed seat ends as `ExitedDueToDriverDelay` — the same status a woman who
     * gave up and pressed the button herself gets, and deliberately so. The consequences have to be
     * identical, and the way to make two paths agree for ever is to give them one status rather
     * than two that somebody has to keep in step. The reason column says which happened:
     * `trip_expired` here, `not_started` when she chose.
     *
     * 🔴 And her attendance becomes `DriverNoShow`, which already existed. That is the record of
     * who was left waiting, and it is the only place that fact lives — a booking says the seat
     * ended, and only the attendance row says somebody stood there.
     */
    private function expire(ScheduledTrip $trip): bool
    {
        return DB::transaction(function () use ($trip): bool {
            $locked = ScheduledTrip::query()->lockForUpdate()->find($trip->getKey());

            // Re-read under the lock: she may have started it in the second it took to get here,
            // and a run that set off is a run that happened.
            if ($locked === null || $locked->status !== ScheduledTripStatus::Scheduled) {
                return false;
            }

            $bookings = Booking::query()
                ->where('scheduled_trip_id', $locked->getKey())
                ->where('status', BookingStatus::Confirmed->value)
                ->with('passenger')
                ->get();

            foreach ($bookings as $booking) {
                $this->endSeat($booking, $locked);
            }

            $locked->forceFill([
                'status' => ScheduledTripStatus::Expired->value,
                // Stamped even if the ladder skipped it, so a day that expired without ever being
                // swept still records that it was in violation.
                'delay_violation_at' => $locked->delay_violation_at ?? now(),
                // The seat count is made to agree with the rows: nothing can book an expired day,
                // but a derived figure should never contradict what the bookings say.
                'seats_taken' => max(0, (int) $locked->seats_taken - (int) $bookings->sum('seats_reserved')),
            ])->save();

            return true;
        });
    }

    private function endSeat(Booking $booking, ScheduledTrip $trip): void
    {
        $booking->forceFill([
            'status' => BookingStatus::ExitedDueToDriverDelay->value,
            'cancelled_at' => now(),
            'cancelled_reason' => 'trip_expired',
            // 🔴 Nothing. The journey she booked never happened, and not because of her.
            'cancellation_fee_piastres' => 0,
        ])->save();

        /*
         * 🔴 `DriverNoShow`, and the row is CREATED if it does not exist — which on an expired
         * morning it never does. Attendance rows are written when a run starts, so a day that never
         * started has none at all, and without one the fact that **she stood there and nobody came**
         * is recorded nowhere: her booking says only that the seat ended.
         *
         * `firstOrNew` on the booking id, following `StartTripAction`: the booking id IS the
         * primary key, so a second row is impossible and a retry is harmless. The status is set
         * explicitly rather than filled, because attendance is what triggers billing and only the
         * Action that decides it may say what it is (pitfall #51).
         *
         * An existing row that somebody already decided is left exactly as it is — the only state
         * worth overwriting is `pending`, which means nobody decided, and on this morning the day
         * itself decided.
         */
        $attendance = Attendance::query()->firstOrNew(['booking_id' => $booking->getKey()]);

        if (! $attendance->exists || $attendance->status === AttendanceStatus::Pending) {
            $attendance->status = AttendanceStatus::DriverNoShow->value;
            $attendance->save();
        }

        BookingEventLog::record(
            $booking,
            BookingEventType::Cancelled,
            BookingActorType::System,
            actorId: null,
            from: BookingStatus::Confirmed,
            metadata: ['driver_delay' => 'trip_expired', 'fee_waived' => true],
        );

        $driverId = $trip->commuteOffer?->driver_profile_id;

        if ($driverId !== null) {
            /*
             * Per passenger, and idempotent on (driver, kind, booking) — so a woman who had already
             * given up and pressed the button herself does not cost the driver a second event.
             */
            ReliabilityLedger::record(
                (string) $driverId,
                ReliabilityEventKind::DriverLateCausedExit,
                booking: $booking,
                scheduledTripId: (string) $trip->getKey(),
                commuteOfferId: (string) $trip->commute_offer_id,
            );
        }

        if ($booking->passenger !== null) {
            Notifier::send($booking->passenger, NotificationType::CommuteDayExpired, params: [
                'name' => $trip->commuteOffer?->driverProfile?->user?->public_first_name ?? '',
                'date' => $trip->trip_date->toFormattedDateString(),
            ], data: [
                'bookingId' => (string) $booking->getKey(),
                'tripId' => (string) $trip->getKey(),
            ]);
        }
    }

    private function remindDriver(ScheduledTrip $trip): void
    {
        $driver = $trip->commuteOffer?->driverProfile?->user;

        if ($driver === null) {
            return;
        }

        Notifier::send($driver, NotificationType::CommuteNotStartedReminder, params: [
            'time' => $trip->departure_local?->format('H:i') ?? '',
        ], data: [
            'tripId' => (string) $trip->getKey(),
            'commuteId' => (string) $trip->commute_offer_id,
        ]);
    }

    /**
     * Everybody holding a seat is told the driver has not set off.
     *
     * ⚠️ It says what is true and stops there. It does not mention that leaving is free — that
     * becomes true at departure and is published on her booking as `driverDelay`, and a
     * notification announcing an entitlement would be read as advice to use it.
     */
    private function warnPassengers(ScheduledTrip $trip): void
    {
        $bookings = Booking::query()
            ->where('scheduled_trip_id', $trip->getKey())
            ->where('status', BookingStatus::Confirmed->value)
            ->with('passenger')
            ->get();

        $driverName = $trip->commuteOffer?->driverProfile?->user?->public_first_name ?? '';

        foreach ($bookings as $booking) {
            if ($booking->passenger instanceof User) {
                Notifier::send($booking->passenger, NotificationType::CommuteNotStartedWarning, params: [
                    'name' => $driverName,
                    'time' => $trip->departure_local?->format('H:i') ?? '',
                ], data: [
                    'bookingId' => (string) $booking->getKey(),
                    'tripId' => (string) $trip->getKey(),
                ]);
            }
        }
    }
}
