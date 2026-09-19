<?php

namespace Database\Factories;

use App\Domains\Safety\Models\SafetyEvent;
use App\Domains\Safety\Models\SosEvent;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SosEvent>
 */
class SosEventFactory extends Factory
{
    protected $model = SosEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'safety_event_id' => SafetyEvent::factory(),
            'countdown_seconds' => 10,
            'is_discreet' => false,
            'location_at_trigger' => new Coordinate(lat: fake()->latitude(29.8, 30.2), lng: fake()->longitude(31.0, 31.6)),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_touch_at' => now()->addSeconds(35),
            'resolution' => 'resolved',
        ]);
    }
}
