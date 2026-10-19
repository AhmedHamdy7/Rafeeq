<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\DisplacementReason;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\WaitlistPositions;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The commute stopped being available, so everybody holding a future seat on it loses it and goes
 * back on the waiting list (Feature 03 v5).
 *
 * v5, "Pause, resume and displaced passengers": *"It cancels ALL future confirmed bookings, not
 * active trip; displaced passengers are notified and automatically added to preserved waiting list.
 * Templates and waitlist persist. Canceled seats never auto-restore."* And, for the car: *"Replace
 * Vehicle requires new verification and cancels all future confirmed bookings across that driver's
 * commutes; displaced riders enter preserved waitlists."*
 *
 * 🔴 **This reverses a decision this repository had already shipped.** Until now, pausing did not
 * touch existing bookings at all, and replacing a vehicle moved the commutes onto the new car and
 * merely notified the passengers (product decision, 2026-10-16, implemented and tested). v5 is
 * dated two days later, states that it overrides contradictory earlier text, and was confirmed by
 * the product owner. The reasoning it wins on is the vehicle case: a replacement car is unverified
 * by definition, so a booking "kept" on it is a seat in a car the driver is not yet allowed to
 * drive. Keeping the seat would be promising a lift that cannot legally happen. Pausing follows the
 * same principle — a paused commute is not running, and a confirmed seat on a journey that is not
 * running is the same false promise.
 *
 * Three things this deliberately does NOT do:
 *
 * 1. **It does not touch a run that has begun.** Only trips still `scheduled` and still in the
 *    future are swept. v5 is explicit: *"Pause Commute cannot cancel an already-started trip.
 *    Driver must use Cancel Active Trip."* Somebody sitting in the car is not displaced by a
 *    setting.
 * 2. **It does not fan the freed seats out to the waiting list.** The ordinary cancellation path
 *    offers a released seat to everybody waiting, which is right when one person drops out and
 *    wrong here: there is no seat to offer, because the commute itself has stopped. Offering one
 *    would send a "a seat just opened" notice for a journey nobody can take.
 * 3. **It does not restore anything later.** v5: *"Canceled seats never auto-restore."* Resume
 *    offers seats to people the driver chooses — see {@see OfferSeatsToWaitlistMembersAction}.
 */
final readonly class DisplaceConfirmedBookingsAction
{
    public function __construct(private CancelBookingAction $cancel) {}

    /**
     * Every future confirmed seat on this one commute.
     *
     * @return int how many bookings were cancelled
     */
    public function forCommute(CommuteOffer $offer, DisplacementReason $reason): int
    {
        return DB::transaction(function () use ($offer, $reason): int {
            $bookings = $this->futureConfirmedBookings($offer);

            if ($bookings->isEmpty()) {
                return 0;
            }

            foreach ($bookings as $booking) {
                $this->cancel->byDisplacement($booking, $offer->driver_profile_id, $reason);
            }

            /*
             * Her request goes back on the list once, however many days she lost. A recurring
             * membership is ONE request covering five bookings; flipping it per booking would write
             * the same row five times and notify her five times for one decision.
             */
            $this->preserveOnWaitlist($offer, $bookings->pluck('seat_request_id')->filter()->unique(), $reason);

            /*
             * Any seat offer still open on these days is closed. They were offers to join a commute
             * that has stopped running, and an untouched one would let somebody accept her way into
             * a seat seconds after everybody else lost theirs.
             */
            $this->closeOpenOffers($bookings->pluck('scheduled_trip_id')->unique());

            $this->tellThem($offer, $bookings, $reason);

            return $bookings->count();
        });
    }

    /**
     * Every future confirmed seat across every commute this driver has that is not archived.
     *
     * One transaction for the lot: a vehicle replacement is one decision, and a half-applied sweep
     * would leave some passengers displaced and others holding seats in a car that is no longer
     * active.
     *
     * @return int how many bookings were cancelled
     */
    public function forDriver(DriverProfile $profile, DisplacementReason $reason): int
    {
        return DB::transaction(function () use ($profile, $reason): int {
            $offers = CommuteOffer::query()
                ->where('driver_profile_id', $profile->user_id)
                ->whereNot('status', CommuteOfferStatus::Archived->value)
                // Ordered so a replacement processed twice takes the same locks in the same order
                // as the first attempt, for the same reason the seat paths sort their trip ids.
                ->orderBy('id')
                ->get();

            $cancelled = 0;

            foreach ($offers as $offer) {
                $cancelled += $this->forCommute($offer, $reason);
            }

            return $cancelled;
        });
    }

    /**
     * Seats that exist, on days that have not started.
     *
     * `Scheduled` and not `Preparing`/`EnRoute`/`InProgress`: the moment a driver begins a run it
     * stops being a plan and becomes a journey people are relying on, and v5 sends her to Cancel
     * Active Trip for those. The date filter is `departure_at`, not the trip date, because a 7am
     * departure is in the past by 8am even though its date is today.
     *
     * @return Collection<int, Booking>
     */
    private function futureConfirmedBookings(CommuteOffer $offer)
    {
        return Booking::query()
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->where('commute_offer_id', $offer->getKey())
                ->where('status', ScheduledTripStatus::Scheduled->value)
                ->where('departure_at', '>', now())
                ->select('id'))
            // Confirmed only. A trial is a flag on a confirmed booking rather than a status, so it
            // is included; a pending request has not been given a seat to lose.
            ->where('status', BookingStatus::Confirmed->value)
            ->with('passenger', 'scheduledTrip')
            ->orderBy('id')
            ->get();
    }

    /**
     * Put each displaced passenger back on the waiting list, keeping the request the driver already
     * approved.
     *
     * 🔴 The row is flipped in place rather than replicated. The alternative — a fresh waitlisted
     * copy beside the old approved one — would leave the `active_request_key` occupied by an
     * approval for a seat that no longer exists, so she could never submit a new request for that
     * commute again. `waitlisted` is outside that generated key, which is exactly what frees her.
     *
     * She keeps her terms, her intro message and her agreed rules, because the driver accepted
     * those and nothing about them changed. What she loses is her position, which goes to the back:
     * the list she is rejoining has people on it who have been waiting, and arriving at the front
     * because the driver changed her car would take their turn.
     *
     * @param  Collection<int, string>  $seatRequestIds
     */
    private function preserveOnWaitlist(CommuteOffer $offer, $seatRequestIds, DisplacementReason $reason): void
    {
        if ($seatRequestIds->isEmpty()) {
            return;
        }

        $back = (int) SeatRequest::query()
            ->where('commute_offer_id', $offer->getKey())
            ->where('status', SeatRequestStatus::Waitlisted->value)
            ->max('waitlist_position');

        $requests = SeatRequest::query()
            ->whereIn('id', $seatRequestIds->all())
            ->where('status', SeatRequestStatus::Approved->value)
            ->orderBy('id')
            ->get();

        foreach ($requests as $request) {
            $request->forceFill([
                'status' => SeatRequestStatus::Waitlisted->value,
                'waitlist_position' => ++$back,
                'displaced_at' => now(),
                'displaced_reason' => $reason->value,
                /*
                 * Cleared, because a waiting list has no deadline. v5: "Offers have no fixed timer
                 * while seat/eligibility persists" — and an `expires_at` inherited from the 48-hour
                 * approval window would quietly drop her off the list she was just promised.
                 */
                'expires_at' => null,
            ])->save();
        }

        // The numbers a waiting passenger reads stay 1, 2, 3 rather than gaining a hole where the
        // approved members used to be.
        WaitlistPositions::renumber($offer->getKey());
    }

    /**
     * @param  Collection<int, string>  $tripIds
     */
    private function closeOpenOffers($tripIds): void
    {
        if ($tripIds->isEmpty()) {
            return;
        }

        SeatOffer::query()
            ->whereIn('scheduled_trip_id', $tripIds->all())
            ->open()
            // `Closed`, not `TakenByOther`: nobody was quicker. The enum keeps those apart
            // precisely so a woman reading her own history is not told she lost a race that never
            // ran — the seat stopped existing, which is what `Closed` says.
            ->update([
                'status' => SeatOfferStatus::Closed->value,
                'closed_at' => now(),
            ]);
    }

    /**
     * One message per passenger, naming what happened.
     *
     * 🔴 Per PASSENGER, not per booking. A recurring rider loses five days to one decision, and
     * five identical notices is how notices get ignored — the same reasoning
     * {@see CancelBookingAction} already applies to its bulk paths. The count travels in the body
     * so she knows the scale without being told five times.
     *
     * Two notification types rather than one with the reason substituted in: the wording is
     * rendered in the RECIPIENT's language by {@see Notifier}, and a reason phrase translated here
     * would arrive in the caller's language inside her sentence.
     *
     * @param  Collection<int, Booking>  $bookings
     */
    private function tellThem(CommuteOffer $offer, $bookings, DisplacementReason $reason): void
    {
        $type = match ($reason) {
            DisplacementReason::CommutePaused => NotificationType::CommutePausedSeatCancelled,
            DisplacementReason::VehicleReplaced => NotificationType::VehicleReplacedSeatCancelled,
        };

        // Loaded before the loop: strict mode turns a lazy load inside one into an exception, and
        // the driver's name is the same for every passenger here.
        $offer->loadMissing('driverProfile.user');

        $driverName = $offer->driverProfile?->user?->public_first_name ?? '';

        foreach ($bookings->groupBy('passenger_user_id') as $forOnePerson) {
            $first = $forOnePerson->first();

            if ($first->passenger === null) {
                continue;
            }

            Notifier::send($first->passenger, $type, params: [
                'name' => $driverName,
                'days' => $forOnePerson->count(),
            ], data: [
                'commuteId' => $offer->getKey(),
                'bookingId' => $first->getKey(),
            ]);
        }
    }
}
