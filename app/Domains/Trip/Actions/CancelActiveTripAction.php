<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Commute\Actions\CancelTripDayAction;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Actions\SuggestAlternativesAction;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Payment\Actions\SettleBookingAction;
use App\Domains\Payment\Support\DriverFees;
use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Support\ReliabilityLedger;
use App\Domains\Safety\Models\LiveShare;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Enums\ActiveTripCancelReason;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\DB;

/**
 * "Cancel Active Trip": the driver stops a run that has already set off (Feature 03 v5 §123–129).
 *
 * > *"**Pause Commute cannot cancel an already-started trip.** Driver must use **Cancel Active
 * > Trip** with applicable fees and Reliability impact; emergency appeal available. Ensure safe
 * > passenger handling and notifications."*
 *
 * 🔴 **Deliberately a different act from "cancel today"**, and not a flag on it.
 * {@see CancelTripDayAction} refuses once the run has begun, and that
 * refusal stays: calling off a morning nobody has left home for and stopping a car with people in
 * it are not the same decision, they do not cost the same, and one endpoint whose meaning flipped
 * on whether the car had moved is how a driver cancels a journey she meant to cancel tomorrow.
 *
 * 🔴 **Passengers are resolved by where they actually are, which is the whole design.** v5 §127–128
 * separates them and so does this:
 *
 * - **Aboard** — she travelled part of the way, so her booking becomes `ended_early` and her
 *   attendance stands. Recording this as a cancellation would erase a journey she made, and the day
 *   money moves it would erase what she owes for it too.
 * - **Not yet collected** — nothing happened to her, so her booking is `cancelled_by_driver`.
 *
 * The money is PAY-24/PAY-25: the driver pays the whole of every affected seat; she pays nothing,
 * except after a breakdown, accident or emergency for the part past the first 25 % of her route.
 *
 * 🔴 **The freed seats are not offered to anybody.** A seat on a run that has stopped is not a seat,
 * and the offer would reach the waiting list for a car that is no longer going anywhere.
 *
 * 🔒 **No "safe stop" step**, per v5 §129: *"Do not add a separate Safe Stop Location selection UI.
 * Use available actual location data; handle unavailable location gracefully."* Where each passenger
 * ends up is read from what the run already recorded, and
 * {@see SuggestAlternativesAction} is what turns it into somewhere
 * she can go next. Nothing here books her anything — v5: *"offer suggestions … never automatically
 * book."*
 *
 * ⚠️ This is not the emergency path. A driver in trouble has SOS, and
 * {@see TripSessionStatus::Emergency} is a run nobody but a reviewer closes.
 * This is the ordinary bad morning — a breakdown, an accident, a family call — and v5's own words
 * for it are that a claimed breakdown *"does not automatically waive fee or Reliability impact"*.
 * So the record is written, and she appeals it with evidence if she has any.
 */
final readonly class CancelActiveTripAction
{
    public function __construct(
        private AdvanceTripAction $advance,
        private SettleBookingAction $settle,
        private RecordTripLocationAction $locations,
        private GeoQueryEngine $geo,
    ) {}

    /**
     * @param  ActiveTripCancelReason  $category  required (TRIP-22, F-13): what the fees read
     * @param  string|null  $note  her own words, for support and her appeal; never sent to the car
     * @return array{trip: ScheduledTrip, endedEarly: int, cancelled: int}
     */
    public function execute(TripSession $session, User $driver, ActiveTripCancelReason $category, ?string $note = null): array
    {
        /*
         * The text written on the day and on each booking: the category, then her words. Support
         * reads it there as it always has; the category itself is kept on the session, typed, for
         * the fee rules.
         */
        $reason = $note === null || trim($note) === '' ? $category->value : $category->value.': '.trim($note);

        /*
         * Only a run that is actually out on the road. A `preparing` run has not left, so calling
         * it off is "cancel today" and costs less; a finished one is finished. Both would otherwise
         * land here through a client retry and be charged as an abandonment.
         */
        if (! $session->current_status->isUnderway()) {
            throw DomainException::of(ErrorCode::TripNotCancellable, fields: [
                'status' => [strtoupper($session->current_status->value)],
            ]);
        }

        return DB::transaction(function () use ($session, $driver, $reason, $category): array {
            // Locked for the same reason every seat-count write is: nobody may read the day
            // between resolving its passengers and marking it cancelled.
            $trip = ScheduledTrip::query()->whereKey($session->scheduled_trip_id)->lockForUpdate()->firstOrFail();

            /*
             * Confirmed only. `pending` is in the enum and nothing in the codebase writes it — it
             * is read defensively in a few places and created nowhere — and both statuses it could
             * legally become from there (`expired`, `cancelled_by_passenger`) would be a lie about
             * who ended it. Sweeping it in here would mean forcing a transition the state machine
             * refuses, to resolve a row that cannot exist.
             */
            $bookings = Booking::query()
                ->where('scheduled_trip_id', $trip->getKey())
                ->where('status', BookingStatus::Confirmed->value)
                ->with('passenger')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $endedEarly = 0;
            $cancelled = 0;

            foreach ($bookings as $booking) {
                $aboard = $this->endJourney($booking, $session, $reason, $driver);

                $aboard ? $endedEarly++ : $cancelled++;

                /*
                 * 🔴 The money (PAY-24, PAY-25; Feature 03 backend file F-6, F-7): the driver pays
                 * the whole of every affected seat, whatever the reason — after a breakdown,
                 * accident or emergency it is reversed if his evidence appeal within 48 hours wins.
                 * A passenger never collected pays nothing.
                 */
                DriverFees::charge(
                    $booking,
                    DriverFees::ACTIVE_CANCEL,
                    FeeSettings::driverActiveCancelPercent(),
                    $category->isUnavoidable() ? 'PAY-24' : 'PAY-25',
                );

                if ($aboard) {
                    $this->settleAboard($booking, $session, $category);
                }

                /*
                 * 🔴 Per passenger, including the ones never collected. Both lost the morning, and
                 * a single event for the run would make stopping a full car cost what stopping an
                 * empty one does — the same reasoning
                 * {@see \App\Domains\Commute\Actions\CancelTripDayAction} records a day by.
                 */
                ReliabilityLedger::record(
                    $booking->driver_profile_id,
                    ReliabilityEventKind::DriverAbandonedActiveTrip,
                    booking: $booking,
                    scheduledTripId: $trip->getKey(),
                    commuteOfferId: $trip->commute_offer_id,
                );

                $this->tell($booking, $driver, $aboard);
            }

            /*
             * Seats to zero, like a cancelled day: the number means "how full is this run", and a
             * run that has stopped is not a run anybody can join.
             */
            $trip->forceFill([
                'cancelled_reason' => $reason,
                'seats_taken' => 0,
            ])->save();

            // A request still waiting on this day cannot be answered with a seat any more.
            SeatRequest::query()
                ->where('scheduled_trip_id', $trip->getKey())
                ->whereIn('status', [SeatRequestStatus::Pending->value, SeatRequestStatus::Waitlisted->value])
                ->update(['status' => SeatRequestStatus::Expired->value, 'waitlist_position' => null]);

            /*
             * Last, and only now: the status says "this run is over" and it must not say that while
             * anybody on it is still marked as travelling. `cancelled()` is the only writer of this
             * status for exactly that reason.
             */
            $session->forceFill(['cancel_reason' => $category->value])->save();

            $this->advance->cancelled($session);

            return ['trip' => $trip->refresh(), 'endedEarly' => $endedEarly, 'cancelled' => $cancelled];
        });
    }

    /**
     * What she pays for the part she rode (PAY-24, PAY-25).
     *
     * - **Any other reason** → nothing.
     * - **Breakdown, accident, emergency** → nothing for up to 25 % of HER route; past that, that
     *   share of the fare, and RAFEEQ's commission on that share. Her share is how far the car had
     *   carried her from her pickup towards her drop-off, measured where it stopped. If the car's
     *   position is not known, she is given the benefit of it and pays nothing.
     *
     * Settled at once (PAY-07), or marked `waived` so the cash sweep never charges the full fare.
     */
    private function settleAboard(Booking $booking, TripSession $session, ActiveTripCancelReason $category): void
    {
        $share = $category->isUnavoidable() ? $this->travelledShare($booking, $session) : 0.0;

        if ($share * 100 <= FeeSettings::proRataFreePercent()) {
            $booking->forceFill(['payment_status' => PaymentStatus::Waived->value])->save();

            return;
        }

        $price = (int) round($booking->price_snapshot_piastres * $share);
        $commission = (int) round($booking->platform_fee_snapshot_piastres * $share);

        $booking->forceFill([
            'price_snapshot_piastres' => $price,
            'platform_fee_snapshot_piastres' => $commission,
            'driver_amount_snapshot_piastres' => $price - $commission,
        ])->save();

        $this->settle->execute($booking);
    }

    /**
     * How much of her own route the car had covered, 0 to 1.
     */
    private function travelledShare(Booking $booking, TripSession $session): float
    {
        $position = $this->locations->current($session);
        $pickup = $booking->pickup_point;
        $dropoff = $booking->dropoff_point;

        if ($position === null || $pickup === null || $dropoff === null) {
            return 0.0;
        }

        $route = $this->geo->distanceMeters($pickup, $dropoff)->metres;

        if ($route <= 0) {
            return 0.0;
        }

        $carried = $this->geo->distanceMeters($pickup, new Coordinate((float) $position->lat, (float) $position->lng))->metres;

        return max(0.0, min(1.0, $carried / $route));
    }

    /**
     * One passenger's journey, ended the way her own facts say it ended.
     *
     * @return bool whether she was in the car
     */
    private function endJourney(Booking $booking, TripSession $session, string $reason, User $driver): bool
    {
        $attendance = Attendance::query()->whereKey($booking->getKey())->first();

        $aboard = $attendance !== null
            && $attendance->status->travelled()
            && $attendance->checked_out_at === null;

        $from = $booking->status;
        $to = $aboard ? BookingStatus::EndedEarly : BookingStatus::CancelledByDriver;

        /*
         * Asked rather than assumed. Both of these are legal from `confirmed` today, and the point
         * of asking is the day one of them is not: a forced write would put a status on a booking
         * that the rest of the product believes is unreachable, and the money is read off this.
         */
        if (! $from->canTransitionTo($to)) {
            throw DomainException::of(ErrorCode::BookingNotCancellable, fields: [
                'status' => [strtoupper($from->value)],
            ]);
        }

        if ($aboard) {
            // She was carried; the record of that is hers to keep. Checked out here because the
            // car is no longer carrying her, whatever the reason.
            $attendance->forceFill(['checked_out_at' => now()])->save();
        } elseif ($attendance !== null && $attendance->status === AttendanceStatus::Pending) {
            /*
             * Nobody decided whether she travelled and now nobody can, so it is settled as
             * `cancelled` rather than left pending. Left pending it would sit in the driver's
             * attendance list for a run that no longer exists, and read to a reviewer as a
             * decision she failed to make.
             */
            $attendance->forceFill(['status' => AttendanceStatus::Cancelled->value])->save();
        }

        $booking->forceFill([
            'status' => $to->value,
            'cancelled_at' => now(),
            'cancelled_reason' => $reason,
            // v5 puts this one on the driver. Nothing is charged to the passenger whose journey
            // somebody else stopped — least of all the one who was already in the car.
            'cancellation_fee_piastres' => 0,
        ])->save();

        BookingEventLog::record(
            $booking,
            $aboard ? BookingEventType::Completed : BookingEventType::Cancelled,
            BookingActorType::Driver,
            $driver->id,
            from: $from,
            metadata: [
                'active_trip_cancelled' => true,
                'was_aboard' => $aboard,
                'reason' => $reason,
                'seats_released' => $booking->seats_reserved,
            ],
        );

        /*
         * 🔒 Whatever she showed a friend dies with the journey. Her own view of the car is already
         * gone — the live position is only readable on a live booking — but a share link hangs off
         * the run, so it is closed by hand. Same reasoning as the passenger's own safety exit.
         */
        LiveShare::query()
            ->where('trip_session_id', $session->getKey())
            ->where('user_id', $booking->passenger_user_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        /*
         * 🔴 **The navigation stops are deliberately left alone**, and this is a correction rather
         * than an omission: the first version of this method closed her pickup and drop-off by
         * writing `reached_at = now()` on them, the way the passenger's own early exit does.
         *
         * That falsifies the one column on this table that cannot be recomputed. `reached_at` means
         * "the driver got here" — {@see BuildNavigationItineraryAction} protects it through every
         * rebuild for that reason — and three other things read it as evidence of where the car got
         * to: {@see \App\Domains\Trip\Support\DriverDelay} decides a free exit by it,
         * {@see \App\Domains\Trip\Support\CallEntitlement} opens a phone number by it, and
         * {@see \App\Domains\Matching\Actions\SuggestAlternativesAction} searches from it when the
         * car's position has expired. Stamping every stop at cancellation time made that last one
         * read back a stop the car never reached — my own fabrication, returned as her location.
         *
         * Nothing needs them closed. There is no itinerary to tidy on a run that is cancelled, and
         * nothing navigates one.
         */
        return $aboard;
    }

    /**
     * 🔴 Two messages, because they are two different pieces of news. A woman standing at a gate
     * needs to know no car is coming. A woman who has just been put out at the roadside knows the
     * journey stopped — what she does not know is whether she is stranded, so hers points at
     * somewhere to go next.
     *
     * 🔒 Neither carries the driver's reason. It goes on the booking for support to read: a reason
     * typed in the minute after a crash is not something to push to five lock screens.
     */
    private function tell(Booking $booking, User $driver, bool $aboard): void
    {
        if ($booking->passenger === null) {
            return;
        }

        Notifier::send(
            $booking->passenger,
            $aboard ? NotificationType::ActiveTripCancelledAboard : NotificationType::ActiveTripCancelled,
            ['name' => $driver->public_first_name],
            ['bookingId' => $booking->getKey(), 'tripId' => (string) $booking->scheduled_trip_id],
        );
    }
}
