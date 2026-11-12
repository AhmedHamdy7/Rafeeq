<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PickupPointRequestStatus;
use App\Domains\Booking\Enums\PointSource;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\FreeCancel;
use App\Domains\Booking\Support\PickupRequestContext;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\ScheduleLock;
use App\Domains\Geo\Models\Place;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use App\Http\Resources\LocalisedLabel;
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

            $this->applyToUpcomingBookings($pickup, $pickup->proposed_point, placeId: null, label: $pickup->proposed_label);

            $this->tellPassenger($pickup, NotificationType::PickupChangeApproved);

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

        $this->tellPassenger($pickup, NotificationType::PickupChangeAlternative, [
            'place' => LocalisedLabel::place($place, null) ?? '',
        ]);

        return $pickup;
    }

    public function reject(PickupPointRequest $pickup): PickupPointRequest
    {
        $this->assertPending($pickup);

        $pickup->forceFill(['status' => PickupPointRequestStatus::Rejected->value])->save();

        $this->tellPassenger($pickup, NotificationType::PickupChangeRejected);

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

            $this->tellDriver($pickup, $place);

            return $pickup;
        });
    }

    public function declineAlternative(PickupPointRequest $pickup): PickupPointRequest
    {
        if ($pickup->status !== PickupPointRequestStatus::SuggestedAlternative) {
            throw DomainException::of(ErrorCode::PickupRequestNotPending);
        }

        $pickup->forceFill(['status' => PickupPointRequestStatus::AlternativeDeclined->value])->save();

        $driver = $this->driverOf($pickup);

        if ($driver !== null) {
            Notifier::send($driver, NotificationType::PickupChangeAlternativeDeclined, params: [
                'name' => $pickup->requestedBy?->public_first_name ?? '',
                'place' => $pickup->alternativePlace === null ? '' : (LocalisedLabel::place($pickup->alternativePlace, null) ?? ''),
            ], data: array_filter([
                'pickupRequestId' => $pickup->id,
                'commuteId' => PickupRequestContext::commuteId($pickup),
                'commuteGroupId' => PickupRequestContext::commuteGroupId($pickup),
                'seatRequestId' => $pickup->seat_request_id,
            ]));
        }

        return $pickup;
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
        ?string $label = null,
    ): void {
        $bookings = Booking::query()
            ->where('passenger_user_id', $pickup->requested_by_user_id)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereIn('scheduled_trip_id', $this->upcomingTripIds($pickup))
            ->with('scheduledTrip')
            ->get();

        /*
         * 🔴 The 12-hour lock, checked for EVERY day before any of them moves (Feature 03 v5).
         *
         * An approved pickup change is the one edit that still reaches into a published commute's
         * future days, which makes it the one place the lock has teeth. Tomorrow morning's kerb
         * stops moving sixteen hours before she stands on it — she has planned her morning around
         * being there, and a point that moved overnight is a woman waiting on the wrong corner.
         *
         * All of them checked first, so the approval is all-or-nothing: applying the days that are
         * still open and refusing the rest would leave her collected from two different places on
         * two consecutive mornings with nothing saying which was which.
         */
        foreach ($bookings as $booking) {
            if ($booking->scheduledTrip !== null) {
                ScheduleLock::assertNotLocked($booking->scheduledTrip);
            }
        }

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
                 * The label is the place's name when a known place was agreed, and the passenger's
                 * own label for her pin.
                 */
                'pickup_lat' => $point?->lat,
                'pickup_lng' => $point?->lng,
                'pickup_label' => $label ?? $this->labelFor($placeId),
            ])->save();

            /*
             * An approved pickup edit moves a stop on every day she still has ahead of her, so each
             * of those days' itineraries is now out of date (Feature 03 v5 §3: "On each accepted
             * booking, cancellation, approved pickup/drop-off edit ... recompute the current
             * itinerary").
             */
            $this->itinerary->bump($booking->scheduled_trip_id);

            FreeCancel::rememberAgreedPickup($booking);
        }

        if ($point === null) {
            return;
        }

        SeatRequest::query()
            ->where('passenger_user_id', $pickup->requested_by_user_id)
            ->where('commute_offer_id', $this->commuteOfferIdOf($pickup))
            ->where('status', SeatRequestStatus::Approved->value)
            ->get()
            ->each(fn (SeatRequest $request) => $request->forceFill([
                'requested_pickup_point' => $point,
                'requested_pickup_lat' => $point->lat,
                'requested_pickup_lng' => $point->lng,
                'requested_pickup_label' => $label ?? $this->labelFor($placeId),
                'requested_pickup_source' => ($placeId === null ? PointSource::CustomMap : PointSource::DriverCounteroffer)->value,
                'requested_pickup_suggestion_id' => null,
            ])->save());
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

    /**
     * @param  array<string, string|int>  $params
     */
    private function tellPassenger(PickupPointRequest $pickup, NotificationType $type, array $params = []): void
    {
        $passenger = $pickup->requestedBy;
        $driver = $this->driverOf($pickup);

        if ($passenger === null) {
            return;
        }

        Notifier::send($passenger, $type, params: [
            'name' => $driver?->public_first_name ?? '',
            ...$params,
        ], data: array_filter([
            'pickupRequestId' => $pickup->id,
            'bookingId' => PickupRequestContext::nextBooking($pickup)?->id,
            'commuteGroupId' => PickupRequestContext::commuteGroupId($pickup),
            'commuteId' => PickupRequestContext::commuteId($pickup),
            'seatRequestId' => $pickup->seat_request_id,
        ]));
    }

    private function tellDriver(PickupPointRequest $pickup, Place $place): void
    {
        $driver = $this->driverOf($pickup);

        if ($driver === null) {
            return;
        }

        Notifier::send($driver, NotificationType::PickupChangeAlternativeAccepted, params: [
            'name' => $pickup->requestedBy?->public_first_name ?? '',
            'place' => LocalisedLabel::place($place, null) ?? '',
        ], data: array_filter([
            'pickupRequestId' => $pickup->id,
            'commuteId' => PickupRequestContext::commuteId($pickup),
            'commuteGroupId' => PickupRequestContext::commuteGroupId($pickup),
        ]));
    }

    private function driverOf(PickupPointRequest $pickup): ?User
    {
        $driverId = CommuteOffer::query()
            ->whereKey(PickupRequestContext::commuteId($pickup))
            ->value('driver_profile_id');

        return $driverId === null ? null : User::query()->find($driverId);
    }

    private function assertPending(PickupPointRequest $pickup): void
    {
        if ($pickup->status !== PickupPointRequestStatus::Pending) {
            throw DomainException::of(ErrorCode::PickupRequestNotPending);
        }
    }
}
