<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PickupPointRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Models\Place;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The driver's three answers to a proposed meeting point, and the passenger's one
 * answer back (Master Plan §889: "موافقة / اقتراح بديل / رفض").
 *
 * **Approve** applies the point to the requester's upcoming bookings on that
 * commute — which is what makes an approval mean something. A status change alone
 * would leave everyone with a row saying "yes" and a booking that still says to
 * wait at the old gate.
 *
 * **Suggest an alternative** is a counter-offer, not a decision: the driver names a
 * place they would rather stop at, and nothing moves until the passenger accepts
 * it. Applying it immediately would relocate somebody's morning without asking
 * them — the mirror image of the thing the whole request flow exists to prevent.
 *
 * **Reject** closes it with no note, deliberately. A driver choosing where to stop
 * their own car does not owe a justification, and demanding one produces boxes
 * filled with "no".
 *
 * Only bookings from now onwards are touched. A booking for a day already
 * travelled is a record of what happened, and rewriting where someone was
 * collected from last Tuesday would be falsifying it.
 */
final readonly class RespondToPickupPointAction
{
    public function __construct(private BuildNavigationItineraryAction $itinerary) {}

    public function approve(PickupPointRequest $pickup): PickupPointRequest
    {
        $this->assertPending($pickup);

        return DB::transaction(function () use ($pickup): PickupPointRequest {
            $pickup->forceFill(['status' => PickupPointRequestStatus::Approved->value])->save();

            $this->applyToUpcomingBookings($pickup, $pickup->proposed_point, placeId: null);

            return $pickup;
        });
    }

    /**
     * A counter-offer. Recorded against the request so the passenger sees what was
     * suggested and by implication what was not agreed to.
     */
    public function suggestAlternative(PickupPointRequest $pickup, Place $place): PickupPointRequest
    {
        $this->assertPending($pickup);

        $pickup->forceFill([
            'status' => PickupPointRequestStatus::SuggestedAlternative->value,
            'alternative_place_id' => $place->id,
        ])->save();

        return $pickup;
    }

    public function reject(PickupPointRequest $pickup): PickupPointRequest
    {
        $this->assertPending($pickup);

        $pickup->forceFill(['status' => PickupPointRequestStatus::Rejected->value])->save();

        return $pickup;
    }

    /**
     * The passenger accepting the driver's counter-offer.
     *
     * This side exists because a suggestion nobody can accept is a dead end: the
     * driver has named a place, and without this the only way forward would be for
     * the passenger to propose that same place again from scratch and wait for a
     * second approval.
     *
     * The agreed point is the PLACE's, not the one originally proposed — the whole
     * content of the counter-offer is that the driver would rather stop somewhere
     * else.
     */
    public function acceptAlternative(PickupPointRequest $pickup): PickupPointRequest
    {
        if ($pickup->status !== PickupPointRequestStatus::SuggestedAlternative) {
            throw DomainException::of(ErrorCode::PickupRequestNotPending);
        }

        $place = $pickup->alternativePlace;

        if ($place === null) {
            // Only reachable if the place was deleted after being suggested; the FK
            // is `nullOnDelete`.
            throw DomainException::of(ErrorCode::PickupNotOnCommute);
        }

        return DB::transaction(function () use ($pickup, $place): PickupPointRequest {
            $pickup->forceFill(['status' => PickupPointRequestStatus::Approved->value])->save();

            $this->applyToUpcomingBookings(
                $pickup,
                new Coordinate((float) $place->lat, (float) $place->lng),
                placeId: $place->id,
            );

            return $pickup;
        });
    }

    /**
     * Writes the agreed meeting point onto every booking the requester still has
     * ahead of them on this commute.
     *
     * `effective_from` is `next_trip`, so "ahead of them" means exactly that:
     * every trip that has not departed.
     */
    private function applyToUpcomingBookings(
        PickupPointRequest $pickup,
        ?Coordinate $point,
        ?string $placeId,
    ): void {
        $bookings = Booking::query()
            ->where('passenger_user_id', $pickup->requested_by_user_id)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereIn('scheduled_trip_id', $this->upcomingTripIds($pickup))
            ->get();

        foreach ($bookings as $booking) {
            $booking->forceFill([
                'pickup_place_id' => $placeId,
                'pickup_point' => $point,
                /*
                 * The label and decimals move with the point, because they are what the agreed
                 * snapshot is READ from — the driver's run card, the navigation itinerary and the
                 * attendance geofence all use `pickup_lat`/`pickup_lng`. Leaving them behind would
                 * mean a meeting point that two people just agreed on, recorded in one column and
                 * contradicted by the three beside it.
                 *
                 * The label is the place's name when a known place was agreed, and null for a bare
                 * pin — honest either way, rather than inventing a description for coordinates.
                 */
                'pickup_lat' => $point?->lat,
                'pickup_lng' => $point?->lng,
                'pickup_label' => $this->labelFor($placeId),
            ])->save();

            /*
             * An approved pickup edit moves a stop on every day she still has ahead of her, so each
             * of those days' itineraries is now out of date (Feature 03 v5 §3: "On each accepted
             * booking, cancellation, approved pickup/drop-off edit ... recompute the current
             * itinerary").
             */
            $this->itinerary->bump($booking->scheduled_trip_id);
        }
    }

    /**
     * The readable name of an agreed place, or null for a bare pin.
     */
    private function labelFor(?string $placeId): ?string
    {
        if ($placeId === null) {
            return null;
        }

        return Place::query()->whereKey($placeId)->value('name');
    }

    /**
     * @return Builder<ScheduledTrip>
     */
    private function upcomingTripIds(PickupPointRequest $pickup)
    {
        return ScheduledTrip::query()
            ->where('commute_offer_id', $this->commuteOfferIdOf($pickup))
            ->where('departure_at', '>', now())
            ->select('id');
    }

    private function commuteOfferIdOf(PickupPointRequest $pickup): string
    {
        return $pickup->seat_request_id !== null
            ? $pickup->seatRequest->commute_offer_id
            : $pickup->groupMember->commuteGroup->commute_offer_id;
    }

    private function assertPending(PickupPointRequest $pickup): void
    {
        if ($pickup->status !== PickupPointRequestStatus::Pending) {
            throw DomainException::of(ErrorCode::PickupRequestNotPending);
        }
    }
}
