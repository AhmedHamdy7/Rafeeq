<?php

use App\Domains\Geo\Models\Place;
use App\Domains\Identity\Models\UserPlace;
use App\Domains\Shared\ValueObjects\Coordinate;

it('round-trips its point through the SpatialPoint cast', function () {
    $place = Place::factory()->create();

    expect($place->fresh()->point)->toBeInstanceOf(Coordinate::class);
});

it('links user_places to a real place once matched', function () {
    $place = Place::factory()->create();
    $userPlace = UserPlace::factory()->create(['place_id' => $place->id]);

    expect($userPlace->place->id)->toBe($place->id);
});
