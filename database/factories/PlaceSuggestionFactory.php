<?php

namespace Database\Factories;

use App\Domains\Geo\Enums\PlaceSuggestionKind;
use App\Domains\Geo\Models\PlaceSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaceSuggestion>
 */
class PlaceSuggestionFactory extends Factory
{
    protected $model = PlaceSuggestion::class;

    public function definition(): array
    {
        return [
            'kind' => PlaceSuggestionKind::CommutePoint,
            'label' => 'Point 90 Mall',
            'label_ar' => 'بوينت ٩٠ مول',
            'search_text' => 'point 90 mall بوينت ٩٠ مول',
            'lat' => 30.0200000,
            'lng' => 31.4900000,
        ];
    }
}
