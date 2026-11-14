<?php

namespace App\Http\Resources;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\PickupEta;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Support\NearPlace;
use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Identity\Models\User;
use App\Domains\Rating\Models\Rating;
use App\Domains\Safety\Support\OtherParty;
use App\Domains\Trip\Support\DriverDelay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking, to either the passenger who holds it or the driver who approved it.
 *
 * 🔒 The exact meeting point appears only once the booking is CONFIRMED.
 *
 * That is one of the Bible's non-negotiable tests — "returns fuzzed pickup
 * coordinates before booking is confirmed", "returns exact coordinates only after
 * confirmation". The reason is concrete: a pickup point is often someone's front
 * door, and a pending or cancelled booking is not a relationship that has earned
 * a home address. Before confirmation the coordinates are rounded to about a
 * hundred metres, which is enough to show the right neighbourhood on a map and not
 * enough to knock on a door.
 *
 * 🔒 The money depends on who is looking (P-3, PAY-39). The passenger is sent the
 * seat price and her total — nothing else. The commute's driver is sent those and
 * the share he keeps. Nobody is sent RAFEEQ's commission or its rate: the app is
 * public, so a figure in a passenger payload is a figure anybody can read.
 *
 * @mixin Booking
 */
final class BookingResource extends JsonResource
{
    /**
     * ~110m. Coarse enough that a building cannot be picked out, fine enough that
     * the map shows the right area.
     */
    private const int FUZZ_DECIMALS = 3;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tripId' => $this->scheduled_trip_id,
            'tripDate' => $this->resource->loadMissing('scheduledTrip')->scheduledTrip?->trip_date?->toDateString(),
            'commuteGroupId' => $this->commute_group_id,

            /*
             * TRIPS-21: the passenger's membership of the commute this seat belongs to — the days she
             * committed to ("Every Sun, Tue, Thu") and whether she is on a trial — so the Upcoming
             * tab's one card per commute is drawn without a `GET /groups/{group}` per card. Null on a
             * one-off seat or once she has left the group.
             */
            'membership' => $this->membershipSummary(),

            /*
             * HISTORY-1: where the journey went and who drove, on every booking of the passenger's
             * own lists — so a History card on a recurring commute is not ten identical rows of a
             * date and a price. The same facts `GET /home` already gives `nextJourney`.
             *
             * Each one appears only when the caller loaded what it is read from, rather than being
             * fetched here. A booking resource that lazy-loaded a commute, its locations and a
             * driver's stats per row would turn every list in the API that returns bookings into a
             * query per row; the lists that want the history fields ask for them.
             */
            'commuteId' => $this->when(
                $this->resource->relationLoaded('scheduledTrip') && $this->scheduledTrip !== null,
                fn (): ?string => $this->scheduledTrip->commute_offer_id,
            ),
            'originLabel' => $this->when(
                $this->routeLoaded(),
                fn (): ?string => JourneyCard::route($this->scheduledTrip->commuteOffer)['originLabel'],
            ),
            'destinationLabel' => $this->when(
                $this->routeLoaded(),
                fn (): ?string => JourneyCard::route($this->scheduledTrip->commuteOffer)['destinationLabel'],
            ),

            /*
             * The driver, as a `person` (§5.1): public first name, badges and numbers.
             *
             * 🔒 Never a full name, a phone or a user id — `PersonSummary` is the one place that
             * line is drawn. Blocking needs the id, and that is `otherPartyUserId` below, which has
             * its own rule about when it may appear.
             */
            'driver' => $this->when(
                $this->resource->relationLoaded('driverProfile'),
                fn (): ?array => $this->driverCard($request),
            ),

            'vehicle' => $this->when(
                $this->resource->relationLoaded('scheduledTrip')
                    && $this->scheduledTrip?->relationLoaded('commuteOffer')
                    && $this->scheduledTrip->commuteOffer?->relationLoaded('vehicle'),
                fn (): ?array => $this->status->grantsExactDetails()
                    ? JourneyCard::vehicle($this->scheduledTrip->commuteOffer, $this->status)
                    : null,
            ),

            'passenger' => $this->when(
                $this->resource->relationLoaded('passenger')
                    && $this->passenger?->relationLoaded('stats')
                    && SeatPriceView::viewerDrives($request, $this->driver_profile_id),
                fn (): array => PersonSummary::for($this->passenger, $request->user()),
            ),
            'replacementOffer' => $this->when(
                $this->resource->relationLoaded('latestReplacementOffer')
                    && SeatPriceView::viewerDrives($request, $this->driver_profile_id),
                fn (): ?array => $this->replacementOffer(),
            ),

            'status' => strtoupper($this->status->value),
            'seatsReserved' => $this->seats_reserved,

            /*
             * The code to check against the car at the kerb, shown beside the plate.
             *
             * 🔒 Returned to BOTH sides on purpose, and that is what makes it usable: a code only
             * the passenger can see is a code nobody can confirm. She asks, the driver reads it
             * off her own list, and the plate and the number agree or they do not.
             *
             * This Resource is already scoped to the two people on the booking — the passenger who
             * holds it and the driver who approved it — so there is nobody else it reaches.
             *
             * ⚠️ It decides nothing: D18 gives attendance to the driver's tap, and the Master Plan
             * rejected a code for that job ("a PIN fails when somebody is running for the car").
             */
            'boardingCode' => $this->boarding_code,

            /*
             * Frozen at approval from the day's own automatic price (PAY-44) and never
             * recomputed: an admin moving a rate tomorrow moves nothing here.
             *
             * - `seatPricePiastres`: one seat. `totalPiastres`: what she pays, seat × seats.
             * - `driverSharePiastres` / `driverShareTotalPiastres`: what the driver keeps, per
             *   seat and in all — 🔒 present ONLY for the commute's own driver.
             */
            'price' => [
                // Exact: the total was written as seat price × seats.
                'seatPricePiastres' => intdiv((int) $this->price_snapshot_piastres, max(1, (int) $this->seats_reserved)),
                'totalPiastres' => (int) $this->price_snapshot_piastres,
                'driverSharePiastres' => $this->when(
                    SeatPriceView::viewerDrives($request, $this->driver_profile_id),
                    fn (): int => intdiv((int) $this->driver_amount_snapshot_piastres, max(1, (int) $this->seats_reserved)),
                ),
                'driverShareTotalPiastres' => $this->when(
                    SeatPriceView::viewerDrives($request, $this->driver_profile_id),
                    fn (): int => (int) $this->driver_amount_snapshot_piastres,
                ),
            ],

            'paymentType' => $this->payment_type->value,
            'paymentStatus' => strtoupper($this->payment_status->value),

            // 🔒 Exact only once confirmed — see the class note.
            'meetingPoint' => $this->meetingPoint(),

            /*
             * Where she is set down, as agreed for this day (Feature 03 v5 §2).
             *
             * 🔒 Fuzzed before confirmation on exactly the same terms as the pickup, and the
             * reason is the same one the class note gives for a meeting point: a drop-off is often
             * a workplace, and a precise one handed out on a pending booking tells a stranger
             * where to wait for her at the other end of the day.
             */
            'dropoffPoint' => $this->dropoffPoint(),

            /*
             * The other person's user id — the driver's to the passenger, the passenger's to the
             * driver — so the trip summary can offer Block and Report (Feature 03 files X-4).
             *
             * 🔒 Null until the journey is over (`COMPLETED`, `ENDED_EARLY`, `NO_SHOW`,
             * `EXITED_DUE_TO_DRIVER_DELAY`); see OtherParty for why not before.
             */
            'otherPartyUserId' => OtherParty::idFor($this->resource, $request->user()),

            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            'cancelledReason' => $this->cancelled_reason,
            'outcomeSeenAt' => $this->outcome_seen_at?->toIso8601String(),
            // TRIPS-17: her note to her driver about where she waits, or null. Both sides read it.
            'pickupNote' => $this->pickup_note,
            'cancellationFeePiastres' => $this->cancellation_fee_piastres,

            /*
             * PAY-16 (L-11): her unpaid fees ride on this ride as a separate line, on top of the
             * fare. The driver collects it in cash and it becomes part of his debt; his screen shows
             * it is not his. Negative is a credit she is discounted (a coupon, a refund). 0: none.
             */
            'feeLinePiastres' => (int) $this->fee_line_piastres,
            // What it is for, as a stable code (`no_show`, `late_cancel`, `credit`, …), or null.
            'feeLineReason' => $this->fee_line_reason,

            /*
             * TRIP-20: set down more than 1 km before her drop-off without asking → `askedAt` is
             * set and she answers `POST /bookings/{booking}/early-drop`. `answer`: null, `yes`, `no`.
             */
            'earlyDrop' => [
                'askedAt' => $this->early_drop_asked_at?->toIso8601String(),
                'distanceMeters' => $this->early_drop_distance_meters === null ? null : (int) $this->early_drop_distance_meters,
                'answer' => $this->early_drop_answer,
            ],

            'createdAt' => $this->created_at->toIso8601String(),

            /*
             * Whether the driver has let her down badly enough that leaving is free right now
             * (Feature 03 v5 §241), and which way — `not_started`, `passed_your_stop` or
             * `late_for_pickup`.
             *
             * 🔴 Null is the normal answer and means "do not show the button". The entitlement is
             * the server's finding, not a claim a client can make, so this is the only way a client
             * can know whether `POST /bookings/{booking}/exit-without-fee` would be accepted — and
             * the two reasons read completely differently on a screen: one is "your driver has not
             * set off", the other is "your driver has passed your stop", and only the second means
             * stop waiting.
             *
             * Computed, never stored: it is true only for as long as it is true, and a column would
             * go stale the moment the driver finally set off.
             */
            'driverDelay' => $this->when(
                $this->resource->relationLoaded('scheduledTrip') && $this->scheduledTrip !== null,
                fn (): ?string => $this->status === BookingStatus::Confirmed
                    ? DriverDelay::reasonFor($this->resource, $this->scheduledTrip)
                    : null,
            ),

            /*
             * "Pickup around 7:55" (DISCOVER-14, handoff B-3) — when the car should reach her pickup
             * that day. Once the day's itinerary is built it is the departure plus the legs up to
             * her stop: the same figure her wait grace starts from, and frozen once the itinerary
             * is (the 16-hour lock). Before that, an estimate along the published route.
             *
             * An estimate either way; the promise is the arrival. Present wherever `trip` is.
             */
            'pickupEta' => $this->when(
                $this->resource->relationLoaded('scheduledTrip') && $this->scheduledTrip !== null,
                fn (): ?string => $this->pickupEta()?->toIso8601String(),
            ),
            'pickupEtaLocal' => $this->when(
                $this->resource->relationLoaded('scheduledTrip') && $this->scheduledTrip !== null,
                fn (): ?string => PickupEta::local($this->scheduledTrip, $this->pickupEta()),
            ),

            /*
             * HISTORY-4: what the CALLER wrote about this journey, in the shape
             * `POST /bookings/{booking}/rating` answers with — or null when they have not rated.
             *
             * 🔒 Only ever the caller's own. Never the other person's, never whether one exists:
             * double-blind is unchanged, and the relation is loaded constrained to the viewer (see
             * `historyRelations()`), so the other rating is not even in memory here.
             */
            'myRating' => $this->when(
                $this->resource->relationLoaded('ratings'),
                fn (): ?array => $this->myRating($request),
            ),

            'trip' => new ScheduledTripResource($this->whenLoaded('scheduledTrip')),
        ];
    }

    /**
     * Worked out once per row, from what the caller loaded: the day's stops when they came with
     * the trip (a list loads them for the page), the route fallback only when the commute did.
     */
    private function replacementOffer(): ?array
    {
        $offer = $this->latestReplacementOffer;

        if ($offer === null) {
            return null;
        }

        return [
            'offerId' => (string) $offer->getKey(),
            'seatRequestId' => (string) $offer->seat_request_id,
            'passengerFirstName' => $offer->seatRequest?->passenger?->public_first_name,
            'status' => strtoupper($offer->status->value),
            'offeredAt' => $offer->created_at?->toIso8601String(),
            'closedAt' => $offer->closed_at?->toIso8601String(),
        ];
    }

    private function pickupEta(): ?CarbonImmutable
    {
        if ($this->pickupEtaResolved) {
            return $this->pickupEtaValue;
        }

        $trip = $this->scheduledTrip;
        $stops = $trip->relationLoaded('navigationStops') ? $trip->navigationStops : null;

        $this->pickupEtaValue = $trip->relationLoaded('commuteOffer')
            ? PickupEta::forBooking(app(GeoQueryEngine::class), $this->resource, $stops)
            : PickupEta::fromItinerary($this->resource, $stops);
        $this->pickupEtaResolved = true;

        return $this->pickupEtaValue;
    }

    /**
     * What `pickupEta` reads (DISCOVER-14): the day's stops and its commute, loaded once for a
     * page rather than one query per row.
     *
     * @return array<int, string>
     */
    public static function pickupEtaRelations(): array
    {
        return ['scheduledTrip.navigationStops', 'scheduledTrip.commuteOffer'];
    }

    private bool $pickupEtaResolved = false;

    private ?CarbonImmutable $pickupEtaValue = null;

    /**
     * Relations every booking list that shows a History card loads (HISTORY-1). Kept beside the
     * fields they feed, so a controller asking for them cannot forget the one the driver's badges
     * need and silently get a badge row that is always empty.
     *
     * 🔒 `ratings` is loaded CONSTRAINED to the viewer's own (HISTORY-4). The other person's rating
     * is not filtered out of the payload afterwards — it is never read from the database in the first
     * place, so no later edit to this class can leak it before the reveal (pitfall #26).
     *
     * @return array<int|string, mixed>
     */
    public static function historyRelations(User $viewer): array
    {
        return [
            'scheduledTrip.commuteOffer.locations',
            'driverProfile.user.stats',
            'driverProfile.user.verifications',
            'ratings' => fn ($query) => $query->where('reviewer_user_id', $viewer->id)->with('tags'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function myRating(Request $request): ?array
    {
        $viewerId = $request->user()?->id;

        // Matched again on the reviewer, on top of the constrained load: a caller who loaded
        // `ratings` unconstrained must still get nothing but their own.
        $own = $this->ratings->first(fn (Rating $rating): bool => $rating->reviewer_user_id === $viewerId);

        return $own === null ? null : (new RatingResource($own))->toArray($request);
    }

    private function routeLoaded(): bool
    {
        return $this->resource->relationLoaded('scheduledTrip')
            && $this->scheduledTrip !== null
            && $this->scheduledTrip->relationLoaded('commuteOffer')
            && $this->scheduledTrip->commuteOffer !== null
            && $this->scheduledTrip->commuteOffer->relationLoaded('locations');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function driverCard(Request $request): ?array
    {
        $driver = $this->driverProfile;

        if ($driver === null || ! $driver->relationLoaded('user') || $driver->user === null) {
            return null;
        }

        return [
            ...PersonSummary::for($driver->user, $request->user(), asDriver: true),
            // From the driver profile, as on `nextJourney`: the profile's counter is the one
            // publishing keeps current (see MatchResultResource).
            'completedTrips' => (int) $driver->completed_trips_count,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function meetingPoint(): ?array
    {
        return $this->placeOrNull($this->pickup_point, $this->pickup_label);
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * TRIPS-21. One indexed lookup per row (`commute_group_id`, `user_id`); a page is at most fifty.
     *
     * @return array{committedDaysMask: int|null, commitment: string, status: string}|null
     */
    private function membershipSummary(): ?array
    {
        if ($this->commute_group_id === null) {
            return null;
        }

        $member = GroupMember::query()
            ->where('commute_group_id', $this->commute_group_id)
            ->where('user_id', $this->passenger_user_id)
            ->whereIn('status', [GroupMemberStatus::Active->value, GroupMemberStatus::NoticeGiven->value])
            ->first();

        if ($member === null) {
            return null;
        }

        return [
            'committedDaysMask' => $member->committed_days_mask,
            // `trial` (her first days, R-5) or `recurring`.
            'commitment' => $member->role === GroupMemberRole::Trial ? 'trial' : 'recurring',
            'status' => strtoupper($member->status->value),
        ];
    }

    private function dropoffPoint(): ?array
    {
        return $this->placeOrNull($this->dropoff_point, $this->dropoff_label);
    }

    /**
     * One agreed place, fuzzed until the booking is confirmed.
     *
     * Shared by both ends of the journey rather than written twice: they are the same privacy rule
     * about the same kind of fact, and a rule written twice is one that will one day disagree with
     * itself.
     *
     * @return array<string, mixed>|null
     */
    private function placeOrNull(mixed $point, ?string $label): ?array
    {
        if ($point === null) {
            return null;
        }

        // The rule itself lives on the enum, because the home screen's next-journey
        // card answers the same question about the plate number.
        $confirmed = $this->status->grantsExactDetails();

        return [
            'lat' => $confirmed ? $point->lat : round($point->lat, self::FUZZ_DECIMALS),
            'lng' => $confirmed ? $point->lng : round($point->lng, self::FUZZ_DECIMALS),
            /*
             * 🔒 The label is withheld until confirmation too. "14 Mohamed Mahmoud St, flat 3" is
             * a precise address no matter how coarsely the coordinates beside it are rounded —
             * fuzzing the pin and publishing the street name would make the fuzzing decorative.
             */
            'label' => $confirmed ? $label : null,
            ...($confirmed ? NearPlace::fields($point->lat, $point->lng) : NearPlace::fields(null, null)),
            // Stated rather than left for the client to infer from the status, so a
            // map can say "approximate" honestly.
            'isExact' => $confirmed,
        ];
    }
}
