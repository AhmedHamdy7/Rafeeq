<?php

namespace Database\Factories;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteType;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommuteDemand>
 */
class CommuteDemandFactory extends Factory
{
    protected $model = CommuteDemand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $originLat = fake()->latitude(29.8, 30.2);
        $originLng = fake()->longitude(31.0, 31.6);
        $destLat = fake()->latitude(29.8, 30.2);
        $destLng = fake()->longitude(31.0, 31.6);

        return [
            'passenger_user_id' => User::factory(),
            'origin_point' => new Coordinate(lat: $originLat, lng: $originLng),
            'destination_point' => new Coordinate(lat: $destLat, lng: $destLng),
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'dest_lat' => $destLat,
            'dest_lng' => $destLng,
            'origin_label' => 'الرحاب',
            'destination_label' => 'القرية الذكية',
            'commute_type' => CommuteType::Recurring,
            'days_mask' => DaysMask::weekdaysSunToThu()->value,
            'preferred_arrival_start' => '07:00:00',
            'preferred_arrival_end' => '07:20:00',
            'max_walk_minutes' => 15,
            'max_detour_minutes' => 15,
            'audience_preference' => CommuteAudience::WomenOnly,
            'status' => CommuteDemandStatus::Active,
            'expires_at' => now()->addDays(14),
        ];
    }
}
