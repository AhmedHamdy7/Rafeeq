<?php

namespace Database\Factories;

use App\Domains\Geo\Models\RouteCache;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteCache>
 */
class RouteCacheFactory extends Factory
{
    protected $model = RouteCache::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $originLat = round(fake()->latitude(29.8, 30.2), 4);
        $originLng = round(fake()->longitude(31.0, 31.6), 4);
        $destLat = round(fake()->latitude(29.8, 30.2), 4);
        $destLng = round(fake()->longitude(31.0, 31.6), 4);

        return [
            'cache_key' => hash('sha256', "{$originLat},{$originLng}-{$destLat},{$destLng}"),
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'dest_lat' => $destLat,
            'dest_lng' => $destLng,
            'polyline' => '}_p~iF~ps|U_ulL',
            'distance_meters' => fake()->numberBetween(2000, 40000),
            'duration_seconds' => fake()->numberBetween(300, 3600),
            'duration_in_traffic_seconds' => fake()->numberBetween(300, 4200),
            'provider' => 'google',
            'fetched_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subDay()]);
    }
}
