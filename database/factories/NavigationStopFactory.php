<?php

namespace Database\Factories;

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NavigationStop>
 */
class NavigationStopFactory extends Factory
{
    protected $model = NavigationStop::class;

    /**
     * A bare driver-start stop, which is the one kind that needs no booking.
     *
     * ⚠️ A factory row is NOT a real itinerary. Stops are derived together — their order and ETAs
     * only mean anything as a set — and {@see BuildNavigationItineraryAction}
     * is what produces a consistent one. This exists so every model is reachable through a factory
     * (standard #21), and for tests that need one row rather than a whole run.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheduled_trip_id' => ScheduledTrip::factory(),
            'booking_id' => null,
            'kind' => NavigationStopKind::DriverStart,
            'sequence' => 0,
            'point' => new Coordinate(30.0594, 31.4913),
            'lat' => 30.0594,
            'lng' => 31.4913,
            'label' => 'Rehab Gate 2',
            'leg_duration_seconds' => 0,
            'leg_distance_meters' => 0,
            'itinerary_version' => 1,
        ];
    }

    /**
     * Reached, as the driver marks a stop on a live run — the one column here that is not derived.
     */
    public function reached(): static
    {
        return $this->state(fn (array $attributes) => [
            'reached_at' => now(),
        ]);
    }
}
