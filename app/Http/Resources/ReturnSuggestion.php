<?php

namespace App\Http\Resources;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\ValueObjects\DaysMask;

/**
 * "Need a ride back too? Nour drives back at 5:00 PM" (S6 of 2026-11-23): the published Return of
 * the Going commute she just asked a seat on, and whether it runs on the days she asked for.
 */
final class ReturnSuggestion
{
    public const string BOOKED = 'booked';

    public const string PENDING = 'pending';

    public const string WAITLISTED = 'waitlisted';

    /**
     * @return array{id: string, departureLocal: string|null, daysMask: int, runsOnRequestedDays: list<int>, trialTripId: string|null, myStatus: string|null}|null
     */
    public static function for(SeatRequest $request, CommuteOffer $offer): ?array
    {
        if ($offer->isReturn()) {
            return null;
        }

        $return = $offer->returnCommute()->with(['weekdaySchedules', 'schedule'])->first();

        if ($return === null || $return->status !== CommuteOfferStatus::Published) {
            return null;
        }

        $returnMask = (int) ($return->schedule?->days_mask ?? 0);
        $isTrial = $request->commitment === SeatRequestCommitment::Trial;
        $trialTripId = null;
        /** @var list<int> $bits */
        $bits = [];

        if ($isTrial) {
            $date = $request->scheduledTrip?->trip_date?->toDateString();

            $trialTripId = $date === null ? null : ScheduledTrip::query()
                ->where('commute_offer_id', $return->getKey())
                ->where('trip_date', $date)
                ->where('status', ScheduledTripStatus::Scheduled->value)
                ->value('id');
        } else {
            $asked = $request->requested_days_mask === null ? $returnMask : (int) $request->requested_days_mask;
            foreach (DaysMask::fromBits($asked & $returnMask)->bits() as $bit) {
                $bits[] = (int) $bit;
            }
        }

        return [
            'id' => (string) $return->getKey(),
            'departureLocal' => CommuteLink::sharedOrNext(CommuteLink::days($return), 'promised_departure_time'),
            'daysMask' => $returnMask,
            'runsOnRequestedDays' => $bits,
            'trialTripId' => $trialTripId === null ? null : (string) $trialTripId,
            'myStatus' => self::myStatus((string) $request->passenger_user_id, $return),
        ];
    }

    public static function myStatus(string $passengerId, CommuteOffer $return): ?string
    {
        $booked = Booking::query()
            ->where('passenger_user_id', $passengerId)
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->where('commute_offer_id', $return->getKey())
                ->where('departure_at', '>', now())
                ->select('id'))
            ->exists();

        if ($booked) {
            return self::BOOKED;
        }

        $open = SeatRequest::query()
            ->where('passenger_user_id', $passengerId)
            ->where('commute_offer_id', $return->getKey())
            ->whereIn('status', [SeatRequestStatus::Pending->value, SeatRequestStatus::Counteroffered->value, SeatRequestStatus::Waitlisted->value])
            ->orderByDesc('created_at')
            ->value('status');

        if ($open === null) {
            return null;
        }

        return ($open instanceof SeatRequestStatus ? $open : SeatRequestStatus::from((string) $open)) === SeatRequestStatus::Waitlisted
            ? self::WAITLISTED
            : self::PENDING;
    }
}
