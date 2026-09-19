<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Rating\Models\Rating;
use App\Domains\Rating\Models\ReviewReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewReport>
 */
class ReviewReportFactory extends Factory
{
    protected $model = ReviewReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rating_id' => Rating::factory(),
            'reporter_id' => User::factory(),
            'reason' => 'تعليق غير لائق',
        ];
    }
}
