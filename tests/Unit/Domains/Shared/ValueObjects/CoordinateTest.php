<?php

use App\Domains\Shared\ValueObjects\Coordinate;

it('accepts valid WGS84 coordinates', function () {
    $point = new Coordinate(lat: 30.0602, lng: 31.4915);

    expect($point->lat)->toBe(30.0602)
        ->and($point->lng)->toBe(31.4915);
});

it('rejects an out-of-range latitude', function () {
    new Coordinate(lat: 91.0, lng: 31.0);
})->throws(InvalidArgumentException::class);

it('rejects an out-of-range longitude', function () {
    new Coordinate(lat: 30.0, lng: 181.0);
})->throws(InvalidArgumentException::class);

it('rounds to 4 decimal places (~11m) for route_cache sharing', function () {
    $point = new Coordinate(lat: 30.06023456, lng: 31.49158765);

    $rounded = $point->roundedTo();

    expect($rounded->lat)->toBe(30.0602)
        ->and($rounded->lng)->toBe(31.4916);
});

it('renders WKT for raw SQL inserts', function () {
    $point = new Coordinate(lat: 30.0602, lng: 31.4915);

    expect($point->toWkt())->toBe('POINT(31.4915 30.0602)');
});
