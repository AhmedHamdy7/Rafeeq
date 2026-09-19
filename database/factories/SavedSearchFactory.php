<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Matching\Models\SavedSearch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedSearch>
 */
class SavedSearchFactory extends Factory
{
    protected $model = SavedSearch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filters = [
            'origin' => 'الرحاب',
            'destination' => 'سمارت فيلدج',
            'women_only' => true,
        ];

        return [
            'user_id' => User::factory(),
            'title' => 'الرحاب ← سمارت فيلدج',
            'filters' => $filters,
            'signature' => SavedSearch::signatureFor($filters),
        ];
    }
}
