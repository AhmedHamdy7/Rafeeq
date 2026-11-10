<?php

namespace App\Domains\Trip\Support;

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\RecordTripLocationAction;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Models\TripWaitTimer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final readonly class LiveStopEtas
{
    private const int CACHE_SECONDS = 30;

    /**
     * @param  array<string, CarbonImmutable|null>  $etas
     * @param  array<string, CarbonImmutable>  $arrivals
     */
    private function __construct(
        public array $etas,
        public ?int $nextStopIndex,
        public ?string $nextStopId,
        public ?int $nextStopDistanceMeters,
        public ?string $carToNextStopPolyline,
        public bool $isLive,
        public ?CarbonImmutable $startedAt,
        public array $arrivals = [],
    ) {}

    /**
     * @param  Collection<int, NavigationStop>  $stops
     */
    public static function for(ScheduledTrip $trip, Collection $stops): self
    {
        $stops = $stops->sortBy('sequence')->values();
        $session = $trip->tripSession;
        $startedAt = $session?->started_at === null ? null : CarbonImmutable::instance($session->started_at);
        $underway = $session !== null && $session->current_status->isUnderway();

        $nextIndex = $stops->search(fn (NavigationStop $stop): bool => $stop->reached_at === null
            && $stop->kind !== NavigationStopKind::DriverStart);
        $nextIndex = $nextIndex === false ? null : $nextIndex;

        $planned = $stops->mapWithKeys(fn (NavigationStop $stop): array => [
            (string) $stop->getKey() => $stop->eta_at === null ? null : CarbonImmutable::instance($stop->eta_at),
        ])->all();

        $arrivals = $session === null ? [] : TripWaitTimer::query()
            ->where('trip_session_id', $session->getKey())
            ->whereNotNull('arrived_at')
            ->get(['booking_id', 'arrived_at'])
            ->mapWithKeys(fn (TripWaitTimer $timer): array => [
                (string) $timer->booking_id => CarbonImmutable::instance($timer->arrived_at),
            ])
            ->all();

        if (! $underway || $nextIndex === null) {
            return new self($planned, $nextIndex, $nextIndex === null ? null : (string) $stops[$nextIndex]->getKey(), null, null, false, $startedAt, $arrivals);
        }

        $position = app(RecordTripLocationAction::class)->current($session);
        $next = $stops[$nextIndex];

        $key = sprintf(
            'trip:%s:live-etas:%s:%s:%s',
            $trip->getKey(),
            $next->getKey(),
            $trip->itinerary_built_version,
            $position?->recordedAt->getTimestamp() ?? 'none',
        );

        $live = Cache::remember($key, self::CACHE_SECONDS, function () use ($stops, $nextIndex, $next, $position, $startedAt): array {
            $now = CarbonImmutable::now();
            $distance = null;
            $polyline = null;

            if ($position !== null && $next->lat !== null) {
                $route = app(GeoQueryEngine::class)->routeBetween(
                    new Coordinate($position->lat, $position->lng),
                    new Coordinate((float) $next->lat, (float) $next->lng),
                );
                $base = $now->addSeconds($route->durationSeconds);
                $distance = (int) round($route->distance->metres);
                $polyline = $route->polyline;
            } else {
                $reached = $stops->slice(0, $nextIndex)
                    ->filter(fn (NavigationStop $stop): bool => $stop->reached_at !== null)
                    ->last();
                $since = $reached?->reached_at !== null ? CarbonImmutable::instance($reached->reached_at) : $startedAt;
                $base = ($since ?? $now)->addSeconds((int) ($next->leg_duration_seconds ?? 0));
            }

            $clock = $base->lessThan($now) ? $now : $base;
            $etas = [];

            foreach ($stops as $index => $stop) {
                if ($index < $nextIndex) {
                    continue;
                }

                if ($index > $nextIndex) {
                    $clock = $clock->addSeconds((int) ($stop->leg_duration_seconds ?? 0));
                }

                $etas[(string) $stop->getKey()] = $clock->toIso8601String();
            }

            return ['etas' => $etas, 'distance' => $distance, 'polyline' => $polyline];
        });

        $etas = $planned;

        foreach ($live['etas'] as $stopId => $at) {
            $etas[$stopId] = CarbonImmutable::parse($at);
        }

        return new self(
            $etas,
            $nextIndex,
            (string) $next->getKey(),
            $live['distance'],
            $live['polyline'],
            true,
            $startedAt,
            $arrivals,
        );
    }

    /**
     * @param  Collection<int, NavigationStop>  $stops
     */
    public static function stopsBefore(Collection $stops, NavigationStop $target): int
    {
        return $stops
            ->filter(fn (NavigationStop $stop): bool => $stop->sequence < $target->sequence
                && $stop->reached_at === null
                && $stop->kind !== NavigationStopKind::DriverStart)
            ->count();
    }

    public function etaFor(NavigationStop $stop): ?CarbonImmutable
    {
        return $this->etas[(string) $stop->getKey()] ?? null;
    }

    public function arrivedAtFor(NavigationStop $stop): ?CarbonImmutable
    {
        if ($stop->kind !== NavigationStopKind::PassengerPickup || $stop->booking_id === null) {
            return null;
        }

        return $this->arrivals[(string) $stop->booking_id] ?? null;
    }

    public function reachedAtFor(NavigationStop $stop): ?CarbonImmutable
    {
        if ($stop->reached_at !== null) {
            return CarbonImmutable::instance($stop->reached_at);
        }

        return $stop->kind === NavigationStopKind::DriverStart ? $this->startedAt : null;
    }
}
