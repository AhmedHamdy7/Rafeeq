<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestKind;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\DuringRideRequests;
use App\Domains\Booking\Support\RequestedPoints;
use App\Domains\Booking\Support\RequestKinds;
use App\Domains\Booking\Support\SeatAvailabilityChecker;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Support\HardFilters;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Support\PickupReachability;
use Illuminate\Support\Facades\DB;

/**
 * A passenger asking for a seat.
 *
 * Rafeeq does not book directly: the driver decides who rides with them. A request
 * is therefore a question, and the seat is not held while it is unanswered —
 * holding it would let one unanswered request block a seat somebody else would
 * have taken.
 *
 * Two things are enforced here that the product depends on.
 *
 * **Agreeing to the group's rules is mandatory.** `agreed_to_rules_at` is NOT
 * NULL in the schema and is checked here: a passenger who did not accept the
 * rules has not agreed to travel on the terms the group runs on, and a driver
 * approving them would be agreeing on their behalf.
 *
 * **Eligibility is re-checked with the same filters as the search.** Reusing
 * `HardFilters` rather than writing a second, simpler check is deliberate: a
 * passenger a driver blocked must not be able to request a seat by calling this
 * endpoint directly with an id obtained some other way. One implementation of
 * "may this person ride with this driver" means the two cannot drift apart.
 *
 * **A women-only commute is visible to everybody, and only a woman may ask**
 * (Feature 03 C-11, BOOK-31): anybody else gets `403 SEAT_REQUEST_WOMEN_ONLY`.
 */
final readonly class RequestSeatAction
{
    public function __construct(
        private SeatAvailabilityChecker $availability,
        private GeoQueryEngine $geo,
        private PickupReachability $reachability,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated upstream
     */
    public function execute(User $passenger, CommuteOffer $offer, array $input): SeatRequest
    {
        if ($offer->driver_profile_id === $passenger->id) {
            throw DomainException::of(ErrorCode::CannotBookOwnCommute);
        }

        if (empty($input['agreedToRules'])) {
            throw DomainException::of(ErrorCode::RulesNotAgreed);
        }

        $commitment = SeatRequestCommitment::from($input['commitment']);
        $trip = $this->tripFor($offer, $input, $commitment);

        $this->assertPassengerMayRide($passenger, $offer);
        $this->assertNoOpenRequest($passenger, $offer);
        $this->assertDaysAreOffered($offer, $input);

        /*
         * 🔴 A run that is already moving is a different question, and v5 §148 allows it: *"driver
         * may accept new active-ride requests if feasible"*. So the ordinary bookability check —
         * which refuses anything past its departure — is replaced rather than relaxed, because the
         * two sets of conditions have almost nothing in common. See
         * {@see assertMayJoinARunInProgress()}.
         */
        $duringRide = $trip !== null && DuringRideRequests::applies($trip);

        if ($trip !== null) {
            // The cheap half first, so a doomed request never costs a routing call.
            $duringRide
                ? DuringRideRequests::assertStillTakingRequests($trip)
                : $this->availability->assertTripIsBookable($trip);

            $this->availability->assertNoDuplicateBooking($trip, $passenger->id);
        }

        /*
         * 🔴 Her two points, resolved and checked before anything is written (Feature 03 v5 §2).
         *
         * Outside the transaction on purpose: this is the one step that may call a routing
         * provider, and holding a transaction open across a network call to measure a detour would
         * put a third party's latency inside a database lock.
         */
        $points = RequestedPoints::fromInput($this->geo, $offer, $input);

        /*
         * 🔴 And the expensive half: can a car that is already on the road still reach her?
         *
         * After the points are resolved, because the answer is about her pickup — and v5 leaves
         * the model open, so {@see PickupReachability} sets out what it is
         * measured from: how far along the route the car has got, and whether the detour fits in
         * the slack left before the arrival the people aboard were promised.
         */
        if ($duringRide) {
            $this->reachability->assertReachable($trip, $points->pickup);
        }

        /*
         * 🔴 What she is asking for (Feature 03 R-1, BOOK-17, BOOK-18, BOOK-33): a seat, or — on a
         * day that is full, or at a time she already rides with somebody else — a place on the
         * waiting list. Never a refusal: "Request to join waiting list" is the whole of what the
         * app offers on a full commute, and it used to answer that with an error for a recurring
         * request. Decided on what the days look like now; see {@see RequestKinds::atRequest()}.
         */
        $kind = $duringRide
            ? SeatRequestKind::SeatRequest
            : RequestKinds::atRequest($offer, $trip, $commitment, $input['requestedDaysMask'] ?? null, (int) ($input['seats'] ?? 1), $passenger->id);

        return DB::transaction(function () use (
            $passenger, $offer, $trip, $commitment, $input, $points, $duringRide, $kind
        ): SeatRequest {
            $request = new SeatRequest;

            $request->fill([
                'passenger_user_id' => $passenger->id,
                'commute_offer_id' => $offer->id,
                'scheduled_trip_id' => $trip?->id,
                'commitment' => $commitment->value,
                'requested_days_mask' => $input['requestedDaysMask'] ?? null,
                'seats' => $input['seats'] ?? 1,
                'meeting_preference' => $input['meetingPreference'],
                'custom_pickup_place_id' => $input['customPickupPlaceId'] ?? null,
                'intro_message' => $input['introMessage'] ?? null,
                // Recorded as a moment, not a boolean: what matters in a dispute
                // is that they agreed and when, against which version of the rules.
                'agreed_to_rules_at' => now(),
                'payment_type' => $input['paymentType'],
            ]);

            /*
             * Her pickup and drop-off, written past the fillable list.
             *
             * 🔒 Deliberately not mass-assignable. Every one of these twelve columns was either
             * computed or verified a few lines above — which suggestion is really this commute's,
             * which way round the journey runs, what the pair costs the driver — and a fillable
             * `requested_pickup_lat` would let a client post coordinates straight past all of it.
             */
            $request->forceFill($points->columns());

            // Not fillable: a request must not be able to declare itself approved,
            // nor what it is asking for.
            $request->status = SeatRequestStatus::Pending->value;
            $request->kind = $kind->value;

            /*
             * 🔴 Three minutes for a run that is already moving (v5 §416), and that is now the ONLY
             * deadline a request can carry (Feature 03 R-4, BOOK-36, decision C2-note). The question
             * itself is perishable: a woman asking to be collected by a car two streets away is
             * asking about where it is now, and an answer ten minutes later answers something else.
             *
             * Every other request stays open until the driver answers it, she withdraws it, or — for
             * one day — that day goes by ({@see RespondToSeatRequestAction::closeDatePassed()}). The
             * 48-hour window that used to close them is gone: a passenger who has asked five drivers
             * is waiting for any one of them, and expiring the other four would take away exactly
             * the fallback BOOK-34 promises her.
             */
            $request->requested_during_ride = $duringRide;

            $request->expires_at = $duringRide
                ? now()->addMinutes((int) config('rafeeq.booking.during_ride_request_expiry_minutes'))
                : null;

            $request->save();

            /*
             * Always the driver's turn now, whichever she asked for (Feature 03 R-1, BOOK-18): a
             * request on a full day used to go straight onto the waiting list without him ever
             * seeing it, and BOOK-18 says he approves or rejects an application like any other.
             * The `kind` travels in the data so his app can open the right card.
             */
            Notifier::send($offer->driverProfile->user, NotificationType::SeatRequested,
                ['name' => $passenger->public_first_name],
                ['seatRequestId' => $request->id, 'kind' => $kind->value],
            );

            return $request;
        });
    }

    /**
     * A trial request names a specific day; a recurring one asks about the pattern
     * and is seated day by day once accepted.
     */
    private function tripFor(CommuteOffer $offer, array $input, SeatRequestCommitment $commitment): ?ScheduledTrip
    {
        if (! isset($input['scheduledTripId'])) {
            if ($commitment === SeatRequestCommitment::Trial) {
                // A trial with no day is not a request anybody can answer.
                throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                    'scheduledTripId' => [__('validation.required', ['attribute' => 'scheduledTripId'])],
                ]);
            }

            return null;
        }

        return ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->whereKey($input['scheduledTripId'])
            ->first()
            // 404-shaped: a trip id that belongs to another commute must not be
            // distinguishable from one that does not exist.
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    /**
     * 🔴 The same eligibility rules the search applies — blocks in both directions,
     * trust level, driver still approved — asked of this one commute, and then the
     * audience.
     *
     * Shared with the search rather than reimplemented, because this endpoint takes
     * an id that may have arrived any way at all.
     */
    private function assertPassengerMayRide(User $passenger, CommuteOffer $offer): void
    {
        $allowed = HardFilters::applyEligibility(
            CommuteOffer::query()->whereKey($offer->id),
            $passenger,
        )->exists();

        if (! $allowed) {
            // 404, not 403: telling somebody a driver blocked that he did would be
            // the one thing the block exists to avoid.
            throw DomainException::of(ErrorCode::NotFound);
        }

        /*
         * 🔴 Women-only (Feature 03 C-11, BOOK-31). A 403 with its own code now, not the 404 it
         * used to be: the commute is shown to everybody, flagged `womenOnly`, so its existence is
         * no secret — and a clear answer beats a "not found" for something she is looking at.
         */
        if (! HardFilters::mayJoinAudience($passenger, $offer)) {
            throw DomainException::of(ErrorCode::SeatRequestWomenOnly);
        }
    }

    /**
     * One open request per passenger per commute. A generated column plus a unique
     * index is what makes it true under a race; this gives an explanation.
     */
    private function assertNoOpenRequest(User $passenger, CommuteOffer $offer): void
    {
        $exists = SeatRequest::query()
            ->where('passenger_user_id', $passenger->id)
            ->where('commute_offer_id', $offer->id)
            ->whereIn('status', [
                SeatRequestStatus::Pending->value,
                SeatRequestStatus::Approved->value,
                SeatRequestStatus::Waitlisted->value,
                /*
                 * A counteroffer is an open conversation: the driver has answered with a different
                 * meeting place and is waiting on this passenger. The generated unique column
                 * enforces it too — this gives her an explanation instead of a database error.
                 */
                SeatRequestStatus::Counteroffered->value,
            ])
            ->exists();

        if ($exists) {
            throw DomainException::of(ErrorCode::AlreadyRequested);
        }
    }

    /**
     * A recurring request may commit to fewer days than the commute runs — three
     * days a week out of five is a normal commitment. What it may not do is commit
     * to a day the commute does not run at all: that day can never be seated, so
     * accepting it would sell a promise nobody can keep.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertDaysAreOffered(CommuteOffer $offer, array $input): void
    {
        $requested = $input['requestedDaysMask'] ?? null;

        if ($requested === null) {
            return;
        }

        $offered = $offer->schedule?->days_mask;

        // Bitwise: every day asked for, with the offered days removed. Anything
        // left is a day this commute does not run.
        if ($offered === null || ((int) $requested & ~$offered) !== 0) {
            throw DomainException::of(ErrorCode::RecurringDaysNotOffered, fields: [
                'requestedDaysMask' => [(string) ($offered ?? 0)],
            ]);
        }
    }
}
