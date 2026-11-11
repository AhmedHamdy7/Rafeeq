<?php

namespace App\Http\Controllers\Api\V1\Search;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Matching\Support\HardFilters;
use App\Domains\Matching\Support\PassengerStanding;
use App\Domains\Matching\Support\SearchRateLimit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matching\BrowseCommutesRequest;
use App\Http\Resources\BrowseCommuteResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class BrowseCommutesController extends Controller
{
    public function __invoke(BrowseCommutesRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $passenger = $request->user();

        SearchRateLimit::assert($passenger->id);

        $womenOnly = filter_var($filters['womenOnly'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $tier = $filters['tier'] ?? null;
        $daysMask = isset($filters['daysMask']) ? (int) $filters['daysMask'] : null;

        $upcoming = fn (Builder $trips) => $trips
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->whereRaw('(seats_total - seats_taken) >= 1')
            ->when($daysMask !== null, fn (Builder $q) => $q->whereHas('commuteSchedule', fn (Builder $schedule) => $schedule
                ->whereRaw('(days_mask & ?) > 0', [$daysMask])));

        $offers = CommuteOffer::query()
            ->tap(fn (Builder $offer) => HardFilters::applyEligibility($offer, $passenger))
            ->where('driver_profile_id', '!=', $passenger->id)
            ->when($womenOnly, fn (Builder $offer) => $offer->where('audience', CommuteAudience::WomenOnly->value))
            ->when($tier !== null, fn (Builder $offer) => $offer->whereHas('vehicle', fn (Builder $vehicle) => $vehicle->where('tier', $tier)))
            ->whereHas('scheduledTrips', $upcoming)
            ->addSelect(['next_departure_at' => ScheduledTrip::query()
                ->select('departure_at')
                ->whereColumn('commute_offer_id', 'commute_offers.id')
                ->tap($upcoming)
                ->orderBy('departure_at')
                ->limit(1)])
            ->orderBy('next_departure_at')
            ->orderBy('commute_offers.id')
            ->paginate(ApiResponse::perPage($request));

        $trips = [];

        foreach ($offers->items() as $offer) {
            $trip = ScheduledTrip::query()
                ->where('commute_offer_id', $offer->id)
                ->tap($upcoming)
                ->orderBy('departure_at')
                ->with([
                    'commuteOffer.locations.place',
                    'commuteOffer.rules',
                    'commuteOffer.vehicle',
                    'commuteOffer.driverProfile.user.stats',
                    'commuteOffer.driverProfile.user.verifications',
                    'commuteSchedule',
                ])
                ->first();

            if ($trip !== null) {
                $trips[] = $trip;
            }
        }

        $standing = PassengerStanding::for($passenger, $trips);

        $rows = BrowseCommuteResource::collection($trips);

        foreach ($rows->collection as $row) {
            $row->withStanding($standing);
        }

        return ApiResponse::paginated($offers, $rows);
    }
}
