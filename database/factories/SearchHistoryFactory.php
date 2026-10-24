<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Matching\Models\SearchHistory;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SearchHistory>
 */
class SearchHistoryFactory extends Factory
{
    protected $model = SearchHistory::class;

    /**
     * The Bible's reference journey as a search: Rehab to Smart Village, Sunday to Thursday.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $criteria = [
            'origin' => ['lat' => 30.0594, 'lng' => 31.4913],
            'destination' => ['lat' => 30.0714, 'lng' => 30.9716],
            'daysMask' => DaysMask::weekdaysSunToThu()->value,
            'arrivalWindowStart' => '06:45:00',
            'arrivalWindowEnd' => '08:00:00',
            'maxWalkMinutes' => 15,
            'maxDetourMinutes' => 15,
        ];

        return [
            'user_id' => User::factory(),
            'criteria' => $criteria,
            // Random rather than hashed from the criteria, so a factory making several for one
            // person does not collide on the unique (user, signature) index.
            'signature' => hash('sha256', (string) Str::ulid()),
            'searched_at' => now(),
        ];
    }
}
