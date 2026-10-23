<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\PickupPointRequestStatus;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Support\Polyline;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;

/**
 * 🔴 What one extra stop actually costs the driver.
 *
 * The two numbers this produces, `added_minutes` and `added_km`, are the ones the
 * migration marks "computed by us, never claimed by the requester" — and the ones
 * the driver's `max_detour_minutes` is checked against. A passenger who could
 * supply them would be deciding how far out of their way the driver goes.
 *
 * The comparison is against the commute's route **as published**, including the
 * pickups the driver already agreed to. Comparing against a bare origin-to-
 * destination line instead would be worse than useless: a commute that already
 * detours for two existing passengers would make a third stop look like it SAVES
 * time, because the line it was measured against was never the journey.
 *
 * One route call, not one per candidate (pitfall #16). The published route was
 * paid for at publish time and is reused from the database; the single call here
 * asks the one question that cannot be answered from it — what the journey becomes
 * with the new stop in it.
 */
final readonly class PickupDetour
{
    private function __construct(
        public float $addedMinutes,
        public float $addedKm,
        /**
         * What the WHOLE run costs in detour if this proposal is approved — the driver's
         * own stops, every custom pickup already agreed to, and this one.
         *
         * Screen 29 shows exactly this: "Your limit is 10 min detour. This request keeps
         * you at +8 min total for the run."
         */
        public float $runTotalMinutes,
    ) {}

    public static function measure(GeoQueryEngine $geo, CommuteOffer $offer, Coordinate $proposed): self
    {
        return self::measureAll($geo, $offer, [$proposed]);
    }

    /**
     * The same measurement for several new stops at once, in the order given.
     *
     * 🔴 Needed because a seat request now proposes TWO points — a pickup and a drop-off (Feature
     * 03 v5 §2) — and measuring them one at a time would understate the cost exactly the way
     * `assertWithin` already describes: each half passes a ten-minute check on its own while the
     * pair together puts the driver well past the limit she agreed to.
     *
     * @param  array<int, Coordinate>  $proposed  the new stops, in the order the passenger reaches them
     */
    public static function measureAll(GeoQueryEngine $geo, CommuteOffer $offer, array $proposed): self
    {
        $published = self::publishedRoute($offer);

        $origin = self::pointOf($offer, CommuteLocationType::Origin);
        $destination = self::pointOf($offer, CommuteLocationType::Destination);

        /*
         * 🔴 Sorted along the road before measuring, and this is a fix.
         *
         * The proposed stops used to be appended AFTER the driver's existing pickups, whatever
         * their geography — so a passenger choosing the driver's FIRST advertised meeting point on
         * a commute that advertises a second one further along was measured as: drive to stop one,
         * drive on to stop two, then **turn back** for her. On a fifty-kilometre corridor that
         * invented about fifty minutes of detour and refused her with `PICKUP_DETOUR_TOO_LONG` for
         * a journey where the driver would simply have passed her on the way.
         *
         * A detour is only meaningful against a route somebody would actually drive, so the stops
         * are ordered the way {@see \App\Domains\Trip\Support\StopOrder} orders a navigation
         * itinerary: by how far along the driver's own line each one sits. A pickup still precedes
         * its own drop-off, because the pair is checked for travel order before it ever reaches
         * here.
         */
        $via = self::inRoadOrder($geo, $origin, $destination, array_merge(self::existingPickups($offer), $proposed));

        $withStop = $geo->routeBetween($origin, $destination, $via);

        /*
         * Never negative. A straight-line engine, and a real provider recomputing
         * a route on live traffic, can both hand back a journey that is nominally
         * quicker with the stop in it. Reporting that as a negative detour would
         * mean a passenger's request appears to give the driver time back, which
         * is not a claim this system should ever make on the strength of an
         * estimate.
         */
        $addedMinutes = round(max(0, $withStop->durationSeconds - $published->durationSeconds) / 60, 1);

        return new self(
            addedMinutes: $addedMinutes,
            addedKm: round(max(0, $withStop->distance->metres - $published->distance->metres) / 1000, 2),
            runTotalMinutes: round(self::runTotalFor($geo, $offer) + $addedMinutes, 1),
        );
    }

    /**
     * What the run already costs in detour, before this proposal.
     *
     * Two parts, and both are needed:
     *
     *   - the driver's OWN stops, as the difference between the route they published and
     *     a straight run from origin to destination
     *   - every custom pickup already approved, which does NOT appear in the published
     *     route (an approval writes the point onto bookings, it does not republish the
     *     commute) and so would otherwise be invisible to this sum
     */
    public static function runTotalFor(GeoQueryEngine $geo, CommuteOffer $offer): float
    {
        $published = self::publishedRoute($offer);

        $direct = $geo->routeBetween(
            self::pointOf($offer, CommuteLocationType::Origin),
            self::pointOf($offer, CommuteLocationType::Destination),
        );

        $ownStops = max(0, $published->durationSeconds - $direct->durationSeconds) / 60;

        return round($ownStops + self::approvedCustomPickupMinutes($offer), 1);
    }

    /**
     * 🔴 The driver's stated limit, applied to the WHOLE RUN rather than to one request.
     *
     * This used to compare only `addedMinutes`, which left a real hole: five separate
     * three-minute pickups each pass a ten-minute check on their own, and the driver ends
     * up with a fifteen-minute detour they never agreed to — approved one honest "yes" at
     * a time.
     *
     * The screen's own wording is the total too: "Your limit is 10 min detour. This
     * request keeps you at +8 min total for the run — within your limit."
     *
     * `max_detour_minutes` is the number the driver chose to say how far out of their way
     * they will go, and it is used as a refusal everywhere else — the search drops
     * commutes past it, saving a route refuses a stop beyond it.
     */
    public function assertWithin(int $maxDetourMinutes): void
    {
        if ($this->runTotalMinutes > $maxDetourMinutes) {
            throw DomainException::of(ErrorCode::PickupDetourTooLong, fields: [
                'addedMinutes' => [(string) $this->addedMinutes],
                'runTotalMinutes' => [(string) $this->runTotalMinutes],
                'maxDetourMinutes' => [(string) $maxDetourMinutes],
            ]);
        }
    }

    public function isWithin(int $maxDetourMinutes): bool
    {
        return $this->runTotalMinutes <= $maxDetourMinutes;
    }

    /**
     * The custom pickups this commute has already agreed to, in minutes.
     *
     * Reached through both keys the row can carry — a seat request or a group membership —
     * because a query following only `seat_request_id` would miss every existing member's
     * approved point and undercount the run.
     */
    private static function approvedCustomPickupMinutes(CommuteOffer $offer): float
    {
        $seatRequests = SeatRequest::query()
            ->where('commute_offer_id', $offer->id)
            ->select('id');

        $members = GroupMember::query()
            ->whereIn('commute_group_id', CommuteGroup::query()
                ->where('commute_offer_id', $offer->id)
                ->select('id'))
            ->select('id');

        return (float) PickupPointRequest::query()
            ->where('status', PickupPointRequestStatus::Approved->value)
            ->where(fn ($query) => $query
                ->whereIn('seat_request_id', $seatRequests)
                ->orWhereIn('group_member_id', $members))
            ->sum('added_minutes');
    }

    /**
     * The route as published. A commute with no stored route has not been
     * published, so there is nothing to measure a detour against — and a published
     * commute always has one.
     */
    private static function publishedRoute(CommuteOffer $offer): Route
    {
        // An unreadable stored route is treated like no route: a refusal, never a 500.
        if ($offer->route_polyline === null || ! Polyline::isReadable($offer->route_polyline)) {
            throw DomainException::of(ErrorCode::PickupNotOnCommute);
        }

        return new Route(
            polyline: $offer->route_polyline,
            distance: Distance::fromMetres((int) $offer->route_distance_meters),
            durationSeconds: (int) $offer->route_duration_seconds,
        );
    }

    /**
     * The stops in the order a driver would reach them.
     *
     * Measured against the bare origin-to-destination line rather than against the route with the
     * stops in it, which would be circular — the thing being ordered would decide the order it was
     * ordered by.
     *
     * @param  array<int, Coordinate>  $via
     * @return array<int, Coordinate>
     */
    private static function inRoadOrder(
        GeoQueryEngine $geo,
        Coordinate $origin,
        Coordinate $destination,
        array $via,
    ): array {
        if (count($via) < 2) {
            return $via;
        }

        $spine = $geo->routeBetween($origin, $destination);

        $withProgress = [];

        foreach ($via as $index => $point) {
            $withProgress[] = [
                'point' => $point,
                'progress' => $geo->progressAlongRoute($spine, $point),
                // A stable tiebreak, so two stops at the same place on the road do not swap between
                // measurements and make the same request cost a different amount twice.
                'index' => $index,
            ];
        }

        usort($withProgress, fn (array $a, array $b): int => $a['progress'] === $b['progress']
            ? $a['index'] <=> $b['index']
            : $a['progress'] <=> $b['progress']);

        return array_map(fn (array $row): Coordinate => $row['point'], $withProgress);
    }

    /**
     * @return array<int, Coordinate>
     */
    private static function existingPickups(CommuteOffer $offer): array
    {
        $via = [];

        foreach ($offer->locations->where('type', CommuteLocationType::Pickup)->sortBy('sequence') as $pickup) {
            $via[] = new Coordinate((float) $pickup->lat, (float) $pickup->lng);
        }

        return $via;
    }

    private static function pointOf(CommuteOffer $offer, CommuteLocationType $type): Coordinate
    {
        $location = $offer->locations->firstWhere('type', $type);

        if ($location === null) {
            throw DomainException::of(ErrorCode::PickupNotOnCommute);
        }

        return new Coordinate((float) $location->lat, (float) $location->lng);
    }
}
