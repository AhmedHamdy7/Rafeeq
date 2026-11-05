<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\DisplacementReason;
use App\Domains\Booking\Enums\SwitchReason;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Booking\Support\FreeCancel;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Outbox\Enums\OutboxEventType;
use App\Domains\Outbox\Support\Outbox;
use App\Domains\Payment\Support\DriverFees;
use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Support\ReliabilityLedger;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use App\Domains\Trip\Actions\RecordMemberWarningAction;
use App\Domains\Trip\Enums\WarningKind;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling a booking, from either side (Chapter 6's cancellation section).
 *
 * The seat is released under the same lock that takes it. Decrementing without
 * the lock is pitfall #2 in reverse: two cancellations racing would each read the
 * old count and write back one less, losing a seat that should have been freed
 * twice — and the seat would stay sold to nobody.
 *
 * `decrement()` is used for the same reason `increment()` is on the way in: one
 * atomic statement rather than a read and a write with a gap.
 *
 * 🔴 The fees are the decided ones (PAY-21, PAY-23): a passenger's late cancel goes on her
 * late-cancel counter ({@see RecordMemberWarningAction}); a driver's cancellation costs him a share
 * of each seat by the notice he gave ({@see DriverFees}). `cancellation_fee_piastres` on the booking
 * is the old configured fee and stays 0.
 */
final readonly class CancelBookingAction
{
    public function __construct(
        private OfferSeatToWaitlistAction $offerSeat,
        private BuildNavigationItineraryAction $itinerary,
        private RecordMemberWarningAction $warnings,
    ) {}

    /**
     * @param  bool  $notify  false for the bulk paths (a planned absence, leaving a group), which
     *                        cancel many days in a loop — see tellTheOtherSide()
     */
    public function byPassenger(Booking $booking, ?string $reason = null, bool $notify = true): Booking
    {
        return $this->cancel(
            $booking,
            BookingStatus::CancelledByPassenger,
            BookingActorType::Passenger,
            $booking->passenger_user_id,
            $reason,
            $notify,
        );
    }

    /**
     * A driver cancelling one person's seat. Cancelling the whole trip is a
     * different operation — it belongs with the trip lifecycle, and every
     * passenger on it has to be told.
     */
    public function byDriver(Booking $booking, string $driverUserId, ?string $reason = null): Booking
    {
        return DB::transaction(function () use ($booking, $driverUserId, $reason): Booking {
            $cancelled = $this->cancel(
                $booking,
                BookingStatus::CancelledByDriver,
                BookingActorType::Driver,
                $driverUserId,
                $reason,
            );

            // PAY-23 (C-5): the driver-cancel fee by the notice he gave, on his side.
            DriverFees::chargeCancellation($cancelled);

            return $cancelled;
        });
    }

    /**
     * The commute stopped being available — paused, or its vehicle replaced — so this seat goes
     * with it (Feature 03 v5).
     *
     * Recorded as cancelled by the DRIVER, because she is: she paused it, or she changed her car.
     * What differs from {@see byDriver} is everything around that one fact, and both differences
     * matter enough to be a separate entry point rather than two flags on the driver path:
     *
     * 1. 🔴 **The freed seat is NOT offered to the waiting list.** Offering it would announce "a
     *    seat just opened up" for a journey that has stopped running — and it would land on the
     *    very people who just lost their own seats on it.
     * 2. **No per-booking notice.** {@see DisplaceConfirmedBookingsAction}
     *    tells each passenger once, naming the reason and the number of days, rather than once per
     *    cancelled day.
     *
     * @param  DisplacementReason  $reason  stored on the booking, so support can answer "why did my
     *                                      Tuesday disappear" without reconstructing it
     * @param  bool  $chargeDriver  whether the driver chose it, and so pays the fee by timing
     */
    public function byDisplacement(Booking $booking, string $driverUserId, DisplacementReason $reason, bool $chargeDriver = false): Booking
    {
        return DB::transaction(function () use ($booking, $driverUserId, $reason, $chargeDriver): Booking {
            $cancelled = $this->cancel(
                $booking,
                BookingStatus::CancelledByDriver,
                BookingActorType::Driver,
                $driverUserId,
                $reason->value,
                notify: false,
                offerToWaitlist: false,
            );

            /*
             * PAY-23 (C-7, C-8, C-10): when HE chose it — paused, deleted, replaced the car — the
             * driver-cancel fee by timing, per seat. Not when the platform did it (a suspended
             * vehicle, a lapsed licence, unpaid commission): nobody chose those.
             */
            if ($chargeDriver) {
                DriverFees::chargeCancellation($cancelled);
            }

            return $cancelled;
        });
    }

    /**
     * Her account was suspended for debt or conduct, so every upcoming seat she holds goes — even
     * inside the 16-hour lock (PAY-46, decision D-0018; Feature 03 backend file L-12).
     *
     * Recorded as cancelled on HER side, because her standing is why, but by the SYSTEM: she did
     * not choose it, so it costs her no cancellation fee and puts nothing on her reliability record
     * (the suspension is the consequence; a second one for the same thing would be double).
     *
     * 🔴 The freed seat IS offered to the waiting list, unlike a displacement: the commute is
     * running, and the driver pays nothing for a seat he did not give up (PAY-46).
     */
    public function bySuspension(Booking $booking, string $reason = 'account_suspended'): Booking
    {
        return $this->cancel(
            $booking,
            BookingStatus::CancelledByPassenger,
            BookingActorType::System,
            null,
            $reason,
        );
    }

    /**
     * The other person on the booking hears about it; the one who cancelled does not need to.
     * The reason is not repeated — it may be personal, and the message shows on a lock screen.
     *
     * Single cancellations only. A member planning a two-week absence cancels ten days in one
     * go, and ten identical notices to the driver for one decision is how notices get ignored;
     * the bulk paths show up in the group's attendance and absences instead.
     */
    private function tellTheOtherSide(Booking $booking, ScheduledTrip $trip, BookingActorType $actorType): void
    {
        // A system cancellation on her side (a suspension) reads to the driver like hers.
        $byPassenger = $actorType !== BookingActorType::Driver;

        $recipient = User::query()->find($byPassenger ? $booking->driver_profile_id : $booking->passenger_user_id);
        $actor = User::query()->find($byPassenger ? $booking->passenger_user_id : $booking->driver_profile_id);

        if ($recipient === null) {
            return;
        }

        Notifier::send($recipient, NotificationType::BookingCancelled, [
            'name' => $actor?->public_first_name ?? '',
            'date' => $trip->trip_date->format('j/n'),
        ], ['bookingId' => $booking->id]);
    }

    /**
     * Puts this cancellation on the right person's reliability record, or on nobody's.
     *
     * 🔴 Three outcomes, and the distinctions are the whole value of the record:
     *
     * - **A driver ending one named passenger's seat** is the most pointed act here and lands on
     *   her driver record.
     * - **A displacement** — a paused commute, a replaced vehicle — lands on her record too, per
     *   displaced passenger, on the same notice ladder as any driver cancellation (Feature 03 files
     *   X-3: < 10h / 10–16h / ≥ 16h) — from the passenger's kerb it is the same morning lost.
     * - **A passenger cancelling her own seat** lands on her passenger record ONLY if she did it
     *   close enough to departure that the driver could not fill it. Cancelling a week out costs
     *   nobody anything and is recorded as nothing at all — a reliability score that punished
     *   ordinary planning would teach people to keep seats they did not want.
     *
     * ⚠️ A driver-delay switch is excluded. `SwitchReason::DriverDelay` records the old booking as
     * cancelled by the driver precisely so a driver's lateness stays out of the passenger's
     * history, and that same reasoning has to reach this ledger or the exclusion is cosmetic.
     */
    private function recordAgainstReliability(
        Booking $booking,
        ScheduledTrip $trip,
        BookingStatus $to,
        ?string $reason,
        BookingActorType $actorType,
    ): void {
        // Nobody chose a system cancellation (a suspension), so it is on nobody's record.
        if ($actorType === BookingActorType::System) {
            return;
        }

        if ($to === BookingStatus::CancelledByDriver) {
            $kind = $reason === DisplacementReason::CommutePaused->value
                || $reason === DisplacementReason::VehicleReplaced->value
                    ? ReliabilityEventKind::DriverDisplacedPassenger
                    : ReliabilityEventKind::DriverCancelledBooking;

            /*
             * 🔒 A driver-delay switch is recorded as cancelled BY THE DRIVER so that her lateness
             * does not land on the passenger — but it is the passenger's own choice to move, and
             * charging the driver a reliability hit for a seat the passenger chose to leave would
             * be the mirror of the same mistake.
             */
            if ($reason === SwitchReason::DriverDelay->value) {
                return;
            }

            ReliabilityLedger::record(
                $booking->driver_profile_id,
                $kind,
                booking: $booking,
                commuteOfferId: $trip->commute_offer_id,
            );

            return;
        }

        if ($to !== BookingStatus::CancelledByPassenger || $trip->departure_at === null) {
            return;
        }

        if (FreeCancel::pickupMovedEarlier($booking)) {
            return;
        }

        $hoursAhead = now()->diffInHours($trip->departure_at, absolute: false);

        // The reliability record looks only at the last 10 hours: far enough out, the seat can be
        // sold again and nothing is recorded.
        if ($hoursAhead <= ReliabilityLedger::lateCancellationHours()) {
            ReliabilityLedger::record(
                $booking->passenger_user_id,
                ReliabilityEventKind::PassengerCancelledLate,
                booking: $booking,
                commuteOfferId: $trip->commute_offer_id,
            );
        }

        /*
         * 🔴 And the late-cancel counter, on its own wider window: within 12 hours of departure
         * (PAY-21; decision B4 split it from the no-show counter). A warning first, then a quarter
         * of the no-show fee at the same rung — held `pending` until departure and dropped if the
         * seat is refilled from the waiting list meanwhile (R-8).
         *
         * Told at once: unlike a lateness, there is no journey she is sitting in.
         */
        if ($hoursAhead < FeeSettings::lateCancelHours() && $booking->passenger !== null) {
            $this->warnings->record($booking->passenger, WarningKind::LateCancel, $booking);
        }
    }

    private function cancel(
        Booking $booking,
        BookingStatus $to,
        BookingActorType $actorType,
        ?string $actorId,
        ?string $reason,
        bool $notify = true,
        bool $offerToWaitlist = true,
    ): Booking {
        // The state machine decides, not this method: a completed or already
        // cancelled booking has no transition to cancelled, and asking the enum
        // keeps that rule in one place.
        if (! $booking->status->canTransitionTo($to)) {
            throw DomainException::of(ErrorCode::BookingNotCancellable, fields: [
                'status' => [strtoupper($booking->status->value)],
            ]);
        }

        return DB::transaction(function () use ($booking, $to, $actorType, $actorId, $reason, $notify, $offerToWaitlist): Booking {
            $from = $booking->status;

            // Locked for the same reason as taking a seat: the count must not be
            // read by anyone else between this read and the write.
            $trip = ScheduledTrip::query()->lockForUpdate()->findOrFail($booking->scheduled_trip_id);

            $booking->forceFill([
                'status' => $to->value,
                'cancelled_at' => now(),
                'cancelled_reason' => $reason,
                /*
                 * 🔴 Charged only when SHE cancelled. This line used to write the configured fee
                 * whoever cancelled and for whatever reason, which is a bug that was harmless only
                 * because the number is currently zero — the day somebody sets it, a woman whose
                 * seat the DRIVER cancelled, or whose commute the driver paused, or whose vehicle
                 * the driver replaced, would be charged for it.
                 *
                 * v5 is explicit that driver fault costs the passenger nothing ("`DRIVER_DELAY_
                 * SWITCH` and driver-fault exit carry no passenger cancellation fee"), and three of
                 * the four cancellation paths in this codebase already wrote a deliberate zero —
                 * {@see SwitchReason::chargesPassenger()},
                 * {@see CancelTripDayAction} and
                 * {@see EndBookingEarlyAction}. This was the fourth, and the
                 * only one that decided by configuration rather than by who was at fault.
                 */
                'cancellation_fee_piastres' => $to === BookingStatus::CancelledByPassenger
                    && $actorType === BookingActorType::Passenger
                    ? (int) config('rafeeq.booking.cancellation_fee_piastres')
                    : 0,
            ])->save();

            // Never below zero, however the data got there: a negative seat count
            // would make the trip look bookable past its capacity.
            $release = min($booking->seats_reserved, $trip->seats_taken);

            if ($release > 0) {
                $trip->decrement('seats_taken', $release);

                /*
                 * The seat that just came free reaches the person who was waiting
                 * for it. Inside this transaction on purpose: if the cancellation
                 * rolls back, the seat never freed, and somebody would have been
                 * moved to the front of a queue for nothing.
                 *
                 * Promotion moves them into the driver's inbox — it does not seat
                 * them. See {@see OfferSeatToWaitlistAction}.
                 *
                 * 🔴 Skipped for a displacement, where the commute itself has stopped running:
                 * there is no seat to offer, and the offer would reach the people who just lost
                 * theirs. See {@see byDisplacement}.
                 */
                if ($offerToWaitlist) {
                    $this->offerSeat->forTrip($trip, $release);
                }
            }

            /*
             * The day's navigation itinerary no longer matches who is on it (Feature 03 v5 §3:
             * "On each accepted booking, cancellation ... recompute the current itinerary").
             *
             * Marked out of date rather than rebuilt here: rebuilding calls a routing provider, and
             * this transaction holds a row lock on the seat count. The rebuild happens when the
             * driver next reads her route.
             */
            $this->itinerary->bump($trip->getKey());

            /*
             * 🔴 It goes on somebody's reliability record (Feature 03 v5: "Cancellation/
             * displacement reduces Reliability Score **even when fee paid**" — so it is recorded
             * here, where the cancellation happens, rather than wherever a fee one day is).
             *
             * Which record, and whether at all, depends on who cancelled and when — see
             * {@see recordAgainstReliability()}.
             */
            $this->recordAgainstReliability($booking, $trip, $to, $reason, $actorType);

            /*
             * 🔴 Announced on the day's channel, in this transaction.
             *
             * The driver's screen shows who is coming; a cancellation she is not told about leaves
             * her planning to stop for somebody who will not be there. The booking id is enough for
             * a client to refresh the one row that changed — no name travels.
             *
             * Deduped on the booking, which is the identity of the state change: a booking is
             * cancelled once, however many times the path is retried.
             */
            Outbox::record(
                OutboxEventType::BookingCancelled,
                Outbox::occurrenceChannel((string) $trip->getKey()),
                [
                    'occurrenceId' => (string) $trip->getKey(),
                    'bookingId' => (string) $booking->getKey(),
                    'status' => $to->value,
                    'seatsReleased' => $release,
                ],
                dedupeKey: 'booking.cancelled:'.$booking->getKey(),
            );

            BookingEventLog::record(
                $booking,
                BookingEventType::Cancelled,
                $actorType,
                $actorId,
                from: $from,
                metadata: ['reason' => $reason, 'seats_released' => $release],
            );

            if ($notify) {
                $this->tellTheOtherSide($booking, $trip, $actorType);
            }

            return $booking;
        });
    }
}
