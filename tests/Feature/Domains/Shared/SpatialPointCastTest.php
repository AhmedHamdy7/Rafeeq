<?php

use App\Domains\Identity\Models\Organization;
use App\Domains\Identity\Models\UserPlace;
use App\Domains\Shared\ValueObjects\Coordinate;

it('round-trips a Coordinate through a POINT column via the SpatialPoint cast', function () {
    $point = new Coordinate(lat: 30.0602, lng: 31.4915);

    $organization = Organization::factory()->create(['location_point' => $point]);
    $fresh = $organization->fresh();

    expect($fresh->location_point)->toBeInstanceOf(Coordinate::class)
        ->and($fresh->location_point->lat)->toEqualWithDelta(30.0602, 0.0001)
        ->and($fresh->location_point->lng)->toEqualWithDelta(31.4915, 0.0001);
});

it('accepts a plain lat/lng array as well as a Coordinate object', function () {
    $place = UserPlace::factory()->create(['point' => ['lat' => 30.05, 'lng' => 31.4]]);

    expect($place->fresh()->point->lat)->toEqualWithDelta(30.05, 0.0001);
});

it('reads a point back immediately after writing it, without needing a refresh', function () {
    // Eloquent only caches a cast value when the input was an object, so an
    // array input used to come back null until the model was refreshed.
    $fromArray = UserPlace::factory()->create(['point' => ['lat' => 30.05, 'lng' => 31.4]]);
    $fromObject = UserPlace::factory()->create(['point' => new Coordinate(lat: 29.9, lng: 31.2)]);

    expect($fromArray->point)->toBeInstanceOf(Coordinate::class)
        ->and($fromArray->point->lat)->toEqualWithDelta(30.05, 0.0001)
        ->and($fromObject->point)->toBeInstanceOf(Coordinate::class)
        ->and($fromObject->point->lng)->toEqualWithDelta(31.2, 0.0001);
});

it('rejects a value that is neither a Coordinate nor a lat/lng array', function () {
    UserPlace::factory()->create(['point' => 'POINT(31.4 30.0)']);
})->throws(InvalidArgumentException::class);

it('hides the raw point and lat/lng from user_places serialization', function () {
    $place = UserPlace::factory()->create();

    expect($place->toArray())->not->toHaveKey('point')
        ->and($place->toArray())->not->toHaveKey('lat')
        ->and($place->toArray())->not->toHaveKey('lng');
});
