<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use Illuminate\Support\Collection;

final class DriverSeatOffers
{
    public const string ROUND_OPEN = 'OPEN';

    public const string ROUND_ACCEPTED = 'ACCEPTED';

    public const string ROUND_CLOSED = 'CLOSED';

    /**
     * @return list<array{releaseEventId: string, tripId: string, commuteId: string, date: string, departureLocal: string|null, status: string, recipientsCount: int, acceptedBy: array{seatRequestId: string, publicFirstName: string|null}|null, members: list<array{offerId: string, seatRequestId: string, publicFirstName: string|null, status: string}>}>
     */
    public static function rounds(string $driverId, array $statuses = []): array
    {
        $offers = SeatOffer::query()
            ->whereIn('scheduled_trip_id', self::upcomingDays($driverId))
            ->with('scheduledTrip', 'seatRequest.passenger')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $offers
            ->groupBy('release_event_id')
            ->map(fn (Collection $round): array => self::round($round))
            ->when($statuses !== [], fn (Collection $rounds) => $rounds->filter(
                fn (array $round): bool => in_array($round['status'], $statuses, true),
            ))
            ->sortBy('departureLocal')
            ->values()
            ->all();
    }

    public static function latestForRequests(Collection $seatRequestIds): array
    {
        if ($seatRequestIds->isEmpty()) {
            return [];
        }

        $offers = SeatOffer::query()
            ->whereIn('seat_request_id', $seatRequestIds->all())
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->where('departure_at', '>', now())
                ->where('status', ScheduledTripStatus::Scheduled->value)
                ->select('id'))
            ->with('scheduledTrip')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $accepted = SeatOffer::query()
            ->whereIn('release_event_id', $offers->pluck('release_event_id')->unique()->all())
            ->where('status', SeatOfferStatus::Accepted->value)
            ->pluck('release_event_id')
            ->flip();

        $latest = [];

        foreach ($offers as $offer) {
            $latest[$offer->seat_request_id] = [
                'id' => (string) $offer->getKey(),
                'tripId' => $offer->scheduled_trip_id,
                'date' => $offer->scheduledTrip->trip_date->toDateString(),
                'status' => self::visibleStatus($offer->status, $accepted->has($offer->release_event_id)),
            ];
        }

        return $latest;
    }

    private static function round(Collection $offers): array
    {
        $trip = $offers->first()->scheduledTrip;
        $winner = $offers->first(fn (SeatOffer $offer): bool => $offer->status === SeatOfferStatus::Accepted);
        $someoneAccepted = $winner !== null;

        $members = $offers->map(fn (SeatOffer $offer): array => [
            'offerId' => (string) $offer->getKey(),
            'seatRequestId' => $offer->seat_request_id,
            'publicFirstName' => $offer->seatRequest?->passenger?->public_first_name,
            'status' => self::visibleStatus($offer->status, $someoneAccepted),
        ])->values();

        return [
            'releaseEventId' => $offers->first()->release_event_id,
            'tripId' => $trip->id,
            'commuteId' => $trip->commute_offer_id,
            'date' => $trip->trip_date->toDateString(),
            'departureLocal' => $trip->departure_local?->format('Y-m-d H:i:s'),
            'status' => match (true) {
                $someoneAccepted => self::ROUND_ACCEPTED,
                $members->contains('status', 'OFFERED') => self::ROUND_OPEN,
                default => self::ROUND_CLOSED,
            },
            'recipientsCount' => $members->count(),
            'acceptedBy' => $winner === null ? null : [
                'seatRequestId' => $winner->seat_request_id,
                'publicFirstName' => $winner->seatRequest?->passenger?->public_first_name,
            ],
            'members' => $members->all(),
        ];
    }

    private static function visibleStatus(SeatOfferStatus $status, bool $someoneAccepted): string
    {
        return match ($status) {
            SeatOfferStatus::Accepted => 'CONFIRMED',
            SeatOfferStatus::Open => 'OFFERED',
            SeatOfferStatus::Declined => $someoneAccepted ? 'OFFER_CLOSED' : 'OFFERED',
            default => 'OFFER_CLOSED',
        };
    }

    private static function upcomingDays(string $driverId): mixed
    {
        return ScheduledTrip::query()
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $driverId)
                ->select('id'))
            ->where('departure_at', '>', now())
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->select('id');
    }
}
