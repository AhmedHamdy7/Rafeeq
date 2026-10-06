<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Support\CommuteChecklist;
use App\Domains\Geo\Actions\MatchCorridorAction;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Matching\Jobs\NotifyMatchingDemands;
use App\Domains\Payment\Support\DriverDebt;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use Illuminate\Support\Facades\DB;

/**
 * Publishing: the moment a draft becomes something passengers can find and book
 * (Chapter 4's Review step).
 *
 * Three things happen here and nowhere else.
 *
 * **The route is computed once.** A draft is edited repeatedly, and asking a
 * routing provider on every edit would bill for journeys nobody published.
 * Publishing is the single point where the road is worth paying for.
 *
 * **The bounding box is written.** This is what the search index filters on, and
 * it is built from every point of the route plus a margin — an offer outside its
 * own box is invisible to a passenger standing next to it, and no later stage
 * can recover from that.
 *
 * **The first trips are generated.** Passengers book days, never recurrence
 * rules, so until trips exist there is nothing to book.
 */
final readonly class PublishCommuteOfferAction
{
    public function __construct(
        private GeoQueryEngine $geo,
        private MatchCorridorAction $matchCorridor,
        private GenerateScheduledTripsAction $generateTrips,
    ) {}

    public function execute(CommuteOffer $offer): CommuteOffer
    {
        CommuteState::assertCanTransitionTo($offer, CommuteOfferStatus::Published);

        $offer->load(['locations', 'schedule', 'vehicle', 'driverProfile']);

        $missing = CommuteChecklist::missingFor($offer);

        if ($missing !== []) {
            throw DomainException::of(ErrorCode::CommuteIncomplete, fields: ['missing' => $missing]);
        }

        // Re-checked at publish, not only at creation: a draft can sit for weeks,
        // and a licence or a vehicle approval can lapse in between.
        CreateCommuteOfferAction::assertDriverMayPublish($offer->driverProfile);
        DriverDebt::assertMayPublish($offer->driverProfile);
        CreateCommuteOfferAction::usableVehicle($offer->driverProfile, $offer->vehicle_id);
        CreateCommuteOfferAction::assertSeatsFitVehicle($offer->vehicle, $offer->seats_total);

        $route = $this->computeRoute($offer);

        return DB::transaction(function () use ($offer, $route): CommuteOffer {
            $box = $route->boundingBox(
                Distance::fromMetres((int) config('rafeeq.geo.search_margin_metres'))
            );

            $offer->forceFill([
                'status' => CommuteOfferStatus::Published->value,
                'published_at' => now(),
                'paused_at' => null,
                'paused_reason' => null,
                'route_polyline' => $route->polyline,
                'route_distance_meters' => $route->distance->metres,
                'route_duration_seconds' => $route->durationSeconds,
                'corridor_id' => $this->corridorFor($offer)?->id,
                ...$box->toColumns(),
            ])->save();

            $this->generateTrips->execute($offer->refresh());

            /*
             * Chapter 5: a passenger who saved a request is told when a matching
             * commute appears. Dispatched after the transaction commits, not
             * inside it — a job that starts before the offer is visible to other
             * connections would find nothing, and one that runs after a rollback
             * would notify people about a commute that was never published.
             */
            DB::afterCommit(fn () => NotifyMatchingDemands::dispatch($offer->id));

            return $offer->load(['locations', 'schedule', 'scheduledTrips']);
        });
    }

    private function computeRoute(CommuteOffer $offer): Route
    {
        $origin = $this->pointOf($offer, CommuteLocationType::Origin);
        $destination = $this->pointOf($offer, CommuteLocationType::Destination);

        $via = [];

        foreach ($offer->locations->where('type', CommuteLocationType::Pickup)->sortBy('sequence') as $pickup) {
            $via[] = new Coordinate((float) $pickup->lat, (float) $pickup->lng);
        }

        return $this->geo->routeBetween($origin, $destination, $via);
    }

    /**
     * Attaching a corridor is best-effort: a journey nobody has named a corridor
     * for is still a perfectly good commute, so a miss must never block
     * publishing.
     */
    private function corridorFor(CommuteOffer $offer): ?Corridor
    {
        return $this->matchCorridor->execute(
            origin: $this->pointOf($offer, CommuteLocationType::Origin),
            destination: $this->pointOf($offer, CommuteLocationType::Destination),
            departureTime: $offer->schedule->departure_time,
            daysMask: $offer->schedule->days_mask,
        );
    }

    private function pointOf(CommuteOffer $offer, CommuteLocationType $type): Coordinate
    {
        $location = $offer->locations->firstWhere('type', $type);

        return new Coordinate((float) $location->lat, (float) $location->lng);
    }
}
