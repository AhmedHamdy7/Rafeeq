<?php

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Database\QueryException;

it('allows only one session per scheduled trip', function () {
    $trip = ScheduledTrip::factory()->create();

    TripSession::factory()->create(['scheduled_trip_id' => $trip->id]);
    TripSession::factory()->create(['scheduled_trip_id' => $trip->id]);
})->throws(QueryException::class);

it('detects a GPS dropout while in progress', function () {
    $session = TripSession::factory()->inProgress()->create(['last_location_at' => now()->subMinutes(5)]);

    expect($session->hasGpsDropout(thresholdSeconds: 60))->toBeTrue();
});

it('does not flag a dropout when not in progress', function () {
    $session = TripSession::factory()->create(['last_location_at' => now()->subMinutes(5)]);

    expect($session->hasGpsDropout(thresholdSeconds: 60))->toBeFalse();
});
