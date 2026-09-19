<?php

namespace Database\Factories;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteDirection;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommuteType;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommuteOffer>
 */
class CommuteOfferFactory extends Factory
{
    protected $model = CommuteOffer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Created eagerly (not passed as a lazy factory to two places): the
        // offer's driver and its vehicle's owner must be the same driver.
        $driverProfile = DriverProfile::factory()->approved()->create();

        return [
            'driver_profile_id' => $driverProfile->user_id,
            'vehicle_id' => Vehicle::factory()->for($driverProfile, 'driverProfile'),
            'commute_type' => CommuteType::Recurring,
            'status' => CommuteOfferStatus::Draft,
            'direction' => CommuteDirection::ToWork,
            'seats_total' => 3,
            'price_per_seat_piastres' => 8000,
            'max_detour_minutes' => 10,
            'max_walk_minutes' => 10,
            'audience' => CommuteAudience::WomenOnly,
            'allows_custom_pickup' => true,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommuteOfferStatus::Published,
            'published_at' => now(),
            'route_polyline' => '}_p~iF~ps|U_ulL',
            'route_distance_meters' => 32000,
            'route_duration_seconds' => 2880,
            'bbox_min_lat' => 30.02,
            'bbox_max_lat' => 30.08,
            'bbox_min_lng' => 31.20,
            'bbox_max_lng' => 31.49,
        ]);
    }

    public function anyVerified(): static
    {
        return $this->state(fn (array $attributes) => ['audience' => CommuteAudience::AnyVerified]);
    }
}
