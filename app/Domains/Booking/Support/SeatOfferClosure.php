<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\SeatOfferClosedReason;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Outbox\Enums\OutboxEventType;
use App\Domains\Outbox\Support\Outbox;

final class SeatOfferClosure
{
    /**
     * @param  array<int, string>  $tripIds
     */
    public static function closeOnTrips(array $tripIds, SeatOfferClosedReason $reason): int
    {
        if ($tripIds === []) {
            return 0;
        }

        $offers = SeatOffer::query()
            ->whereIn('scheduled_trip_id', $tripIds)
            ->open()
            ->with('seatRequest')
            ->get();

        foreach ($offers as $offer) {
            self::close($offer, SeatOfferStatus::Closed, $reason);
        }

        return $offers->count();
    }

    public static function closeForRequest(string $seatRequestId, SeatOfferClosedReason $reason): int
    {
        $offers = SeatOffer::query()
            ->where('seat_request_id', $seatRequestId)
            ->open()
            ->with('seatRequest')
            ->get();

        foreach ($offers as $offer) {
            self::close($offer, SeatOfferStatus::Closed, $reason);
        }

        return $offers->count();
    }

    public static function close(SeatOffer $offer, SeatOfferStatus $status, SeatOfferClosedReason $reason): void
    {
        $offer->forceFill([
            'status' => $status->value,
            'closed_at' => now(),
            'closed_reason' => $reason->value,
        ])->save();

        self::announce($offer);
    }

    public static function announce(SeatOffer $offer): void
    {
        $request = $offer->seatRequest;

        if ($request === null) {
            return;
        }

        Outbox::record(
            OutboxEventType::OfferClosed,
            Outbox::userChannel((string) $request->passenger_user_id),
            [
                'offerId' => (string) $offer->getKey(),
                'occurrenceId' => (string) $offer->scheduled_trip_id,
                'reason' => $offer->status->value,
                'closedReason' => $offer->closed_reason?->value,
                'stillWaiting' => $request->fresh()?->status === SeatRequestStatus::Waitlisted,
            ],
            dedupeKey: 'offer.closed:'.$offer->getKey(),
        );
    }
}
