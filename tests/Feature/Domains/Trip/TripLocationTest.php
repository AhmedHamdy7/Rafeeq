<?php

use App\Domains\Trip\Models\TripLocation;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Schema;

it('has no updated_at column', function () {
    expect(Schema::hasColumn('trip_locations', 'updated_at'))->toBeFalse();
});

it('belongs to a trip session and orders by recorded_at', function () {
    $session = TripSession::factory()->create();

    TripLocation::factory()->for($session, 'tripSession')->create(['recorded_at' => now()->subMinute()]);
    TripLocation::factory()->for($session, 'tripSession')->create(['recorded_at' => now()]);

    $ordered = $session->locations()->orderBy('recorded_at')->pluck('recorded_at');

    expect($ordered->first()->lessThan($ordered->last()))->toBeTrue();
});
