<?php

namespace Database\Factories;

use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PickupPointRequest>
 */
class PickupPointRequestFactory extends Factory
{
    protected $model = PickupPointRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seatRequest = SeatRequest::factory()->create();

        return [
            'seat_request_id' => $seatRequest->id,
            'requested_by_user_id' => $seatRequest->passenger_user_id,
            'proposed_point' => new Coordinate(lat: fake()->latitude(29.8, 30.2), lng: fake()->longitude(31.0, 31.6)),
            'proposed_label' => 'منطقة المستثمرين الجنوبية',
            'added_minutes' => 4.0,
            'added_km' => 1.8,
        ];
    }
}
