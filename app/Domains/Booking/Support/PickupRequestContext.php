<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Geo\Actions\SearchPlacesAction;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use App\Http\Resources\LocalisedLabel;

final class PickupRequestContext
{
    private const int AREA_RADIUS_METRES = 3000;

    public static function commuteId(PickupPointRequest $pickup): ?string
    {
        return $pickup->seat_request_id !== null
            ? $pickup->seatRequest?->commute_offer_id
            : $pickup->groupMember?->commuteGroup?->commute_offer_id;
    }

    public static function commuteGroupId(PickupPointRequest $pickup): ?string
    {
        return $pickup->groupMember?->commute_group_id;
    }

    public static function nextBooking(PickupPointRequest $pickup): ?Booking
    {
        if ($pickup->group_member_id === null) {
            return null;
        }

        $commuteId = self::commuteId($pickup);

        if ($commuteId === null) {
            return null;
        }

        return Booking::query()
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->where('bookings.passenger_user_id', $pickup->requested_by_user_id)
            ->where('bookings.status', BookingStatus::Confirmed->value)
            ->where('scheduled_trips.commute_offer_id', $commuteId)
            ->where('scheduled_trips.departure_at', '>', now())
            ->orderBy('scheduled_trips.departure_at')
            ->select('bookings.*')
            ->first();
    }

    public static function tripId(PickupPointRequest $pickup, ?Booking $next): ?string
    {
        return $pickup->seat_request_id !== null
            ? $pickup->seatRequest?->scheduled_trip_id
            : $next?->scheduled_trip_id;
    }

    /**
     * @return array{label: string|null, lat: float, lng: float, isExact: bool}|null
     */
    public static function currentPoint(PickupPointRequest $pickup, ?Booking $next): ?array
    {
        if ($pickup->seat_request_id !== null) {
            $request = $pickup->seatRequest;

            if ($request === null || $request->requested_pickup_lat === null) {
                return null;
            }

            return [
                'label' => $request->requested_pickup_label,
                'lat' => (float) $request->requested_pickup_lat,
                'lng' => (float) $request->requested_pickup_lng,
                'isExact' => true,
            ];
        }

        if ($next === null || $next->pickup_lat === null) {
            return null;
        }

        return [
            'label' => $next->pickup_label,
            'lat' => (float) $next->pickup_lat,
            'lng' => (float) $next->pickup_lng,
            'isExact' => true,
        ];
    }

    public static function areaLabel(Coordinate $point): ?string
    {
        $place = app(SearchPlacesAction::class)->nearest($point, Distance::fromMetres(self::AREA_RADIUS_METRES));

        return $place === null ? null : LocalisedLabel::of($place->district ?? $place->city);
    }
}
