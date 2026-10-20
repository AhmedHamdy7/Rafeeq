<?php

namespace App\Domains\Trip\Support;

use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use Carbon\CarbonImmutable;

/**
 * One stop in a composed itinerary, before anything is written down.
 *
 * Separate from {@see NavigationStop} on purpose: the route-preview
 * endpoint composes an itinerary that must NOT be persisted — v5 §2 is explicit that pending points
 * never enter the confirmed route — and the only safe way to guarantee that is for the composition
 * to produce something that is not a model with a `save()` on it.
 */
final readonly class ItineraryStop
{
    /**
     * The booking id a PROPOSED pair of points carries in a route preview.
     *
     * 🔴 A sentinel rather than null, and the ordering is why: a preview has no booking yet — that
     * is the whole point of it — but {@see StopOrder} needs to know the two points belong to the
     * same person to keep her pickup before her drop-off. Null would make them look like the
     * driver's own endpoints, which are ordered by position alone.
     *
     * It is a constant because two layers compare against it: the action that composes the preview
     * and the resource that marks those stops as the proposal. A string literal in both would be a
     * silent failure the day one of them changed — the preview would still render, with the
     * proposal shown as an existing passenger.
     */
    public const string PROPOSED_BOOKING_ID = 'preview';

    public function __construct(
        public NavigationStopKind $kind,
        public Coordinate $point,
        public ?string $label,
        public int $sequence,
        /** Null for the driver's own two endpoints. */
        public ?string $bookingId = null,
        /** The leg INTO this stop. Zero for the first, which is where she starts. */
        public int $legDurationSeconds = 0,
        public int $legDistanceMeters = 0,
        /**
         * When she is expected here. Null when the day has no departure time to count from, or
         * when a leg could not be routed — v5: "Do not create fake distance/duration/ETA values."
         */
        public ?CarbonImmutable $etaAt = null,
        /**
         * Carried through a rebuild rather than recomputed: this is the driver's progress on a live
         * run and nothing else in the system knows it.
         */
        public ?CarbonImmutable $reachedAt = null,
    ) {}

    /**
     * The columns this stop occupies on `occurrence_navigation_stops`.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'kind' => $this->kind->value,
            'sequence' => $this->sequence,
            'point' => $this->point,
            'lat' => $this->point->lat,
            'lng' => $this->point->lng,
            'label' => $this->label,
            'eta_at' => $this->etaAt,
            'leg_duration_seconds' => $this->legDurationSeconds,
            'leg_distance_meters' => $this->legDistanceMeters,
            'reached_at' => $this->reachedAt,
        ];
    }
}
