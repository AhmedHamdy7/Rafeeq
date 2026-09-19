<?php

namespace Database\Factories;

use App\Domains\Rating\Enums\RatingTagValue;
use App\Domains\Rating\Models\Rating;
use App\Domains\Rating\Models\RatingTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatingTag>
 */
class RatingTagFactory extends Factory
{
    protected $model = RatingTag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rating_id' => Rating::factory(),
            'tag' => RatingTagValue::SafeDriving,
        ];
    }
}
