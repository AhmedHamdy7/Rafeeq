<?php

namespace App\Http\Controllers\Api\V1\Trip;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Rating\Enums\RatingDirection;
use App\Domains\Rating\Models\Rating;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trip\ListDriverTripsRequest;
use App\Http\Resources\DriverDayResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class DriverTripController extends Controller
{
    public function index(ListDriverTripsRequest $request): JsonResponse
    {
        $upcoming = $request->validated('scope') === ListDriverTripsRequest::UPCOMING;

        $days = ScheduledTrip::query()
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $request->user()->id)
                ->when($upcoming, fn ($query) => $query->where('status', CommuteOfferStatus::Published->value))
                ->select('id'))
            ->when($request->filled('commuteId'), fn ($query) => $query->where('commute_offer_id', $request->validated('commuteId')))
            ->whereIn('status', $upcoming
                ? [ScheduledTripStatus::Scheduled->value, ScheduledTripStatus::Preparing->value, ScheduledTripStatus::EnRoute->value, ScheduledTripStatus::InProgress->value]
                : [ScheduledTripStatus::Completed->value, ScheduledTripStatus::Cancelled->value, ScheduledTripStatus::Expired->value])
            ->with('commuteOffer.locations', 'tripSession')
            ->withCount([
                'bookings as passenger_count' => fn ($query) => $query->whereIn('status', [
                    BookingStatus::Confirmed->value,
                    BookingStatus::Completed->value,
                    BookingStatus::EndedEarly->value,
                ]),
                'bookings as passengers_notified' => fn ($query) => $query->whereIn('status', [
                    BookingStatus::CancelledByDriver->value,
                    BookingStatus::ExitedDueToDriverDelay->value,
                ]),
            ])
            ->orderBy('departure_at', $upcoming ? 'asc' : 'desc')
            ->orderBy('id', $upcoming ? 'asc' : 'desc')
            ->paginate(ApiResponse::perPage($request));

        $ratings = $this->ratings(collect($days->items())->pluck('id')->all());
        $rows = [];

        foreach ($days->items() as $day) {
            $rows[] = new DriverDayResource($day, $ratings[$day->id] ?? null);
        }

        return ApiResponse::paginated($days, $rows);
    }

    private function ratings(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        return Rating::query()
            ->visible()
            ->ofCompletedRides()
            ->where('direction', RatingDirection::PassengerToDriver->value)
            ->join('bookings', 'bookings.id', '=', 'ratings.booking_id')
            ->whereIn('bookings.scheduled_trip_id', $tripIds)
            ->groupBy('bookings.scheduled_trip_id')
            ->selectRaw('bookings.scheduled_trip_id as trip_id, AVG(ratings.stars) as average')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->trip_id => round((float) $row->average, 1)])
            ->all();
    }
}
