<?php

use App\Domains\Rating\Enums\RatingTagValue;
use App\Domains\Rating\Models\Rating;
use App\Domains\Rating\Models\RatingTag;
use Illuminate\Database\QueryException;

it('rejects the same tag twice on one rating', function () {
    $rating = Rating::factory()->create();

    RatingTag::factory()->for($rating)->create(['tag' => RatingTagValue::OnTime]);
    RatingTag::factory()->for($rating)->create(['tag' => RatingTagValue::OnTime]);
})->throws(QueryException::class);

it('allows several distinct tags on one rating', function () {
    $rating = Rating::factory()->create();

    RatingTag::factory()->for($rating)->create(['tag' => RatingTagValue::OnTime]);
    RatingTag::factory()->for($rating)->create(['tag' => RatingTagValue::CleanCar]);

    expect($rating->tags)->toHaveCount(2);
});
