<?php

use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;

it('round-trips its origin and destination points', function () {
    $demand = CommuteDemand::factory()->create();

    expect($demand->fresh()->origin_point)->toBeInstanceOf(Coordinate::class)
        ->and($demand->fresh()->destination_point)->toBeInstanceOf(Coordinate::class);
});

it('exposes days_mask as a value object', function () {
    $demand = CommuteDemand::factory()->create();

    expect($demand->daysMask())->toBeInstanceOf(DaysMask::class);
});
