<?php

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Support\Polyline;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id, ['allowsCustomPickup' => true]);
});

function publishRoadCommute(string $token, string $commuteId): CommuteOffer
{
    test()->withToken($token)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    return CommuteOffer::query()->findOrFail($commuteId);
}

it('sends the measured road on a draft route preview', function () {
    $preview = test()->withToken($this->driverToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/route-preview")->assertOk()->json('data');

    expect($preview['polyline'])->toBeString()
        ->and(Polyline::isReadable($preview['polyline']))->toBeTrue()
        ->and($preview['distanceMeters'])->toBeGreaterThan(0);
});

it('sends the published road to the driver and on the public detail', function () {
    $offer = publishRoadCommute($this->driverToken, $this->commuteId);

    $mine = test()->withToken($this->driverToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}")->assertOk()->json('data');
    $preview = test()->withToken($this->driverToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/route-preview")->assertOk()->json('data');
    $public = test()->withToken(verifiedPassenger('01112223344'))
        ->getJson("/api/v1/commutes/{$this->commuteId}/public")->assertOk()->json('data');

    expect($mine['routePolyline'])->toBe($offer->route_polyline)
        ->and($preview['polyline'])->toBe($offer->route_polyline)
        ->and($public['route']['polyline'])->toBe($offer->route_polyline);
});

it('stops sending a road older than the keep period', function () {
    publishRoadCommute($this->driverToken, $this->commuteId);

    CommuteOffer::query()->whereKey($this->commuteId)->update(['route_fetched_at' => now()->subDays(31)]);

    $mine = test()->withToken($this->driverToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}")->assertOk()->json('data');
    $public = test()->withToken(verifiedPassenger('01112223344'))
        ->getJson("/api/v1/commutes/{$this->commuteId}/public")->assertOk()->json('data');

    expect($mine['routePolyline'])->toBeNull()
        ->and($mine['routeDistanceMeters'])->toBeGreaterThan(0)
        ->and($public['route']['polyline'])->toBeNull();
});

it('sends the road through her points on the pickup and drop-off previews', function () {
    $offer = publishRoadCommute($this->driverToken, $this->commuteId);
    $pax = verifiedPassenger('01112223344');

    $pickup = test()->withToken($pax)
        ->postJson("/api/v1/commutes/{$this->commuteId}/pickup-preview", onTheWay())->assertOk()->json('data');
    $dropoff = test()->withToken($pax)
        ->postJson("/api/v1/commutes/{$this->commuteId}/dropoff-preview", [
            'lat' => 30.0684, 'lng' => 31.1015, 'pickup' => onTheWay(),
        ])->assertOk()->json('data');

    expect(Polyline::isReadable($pickup['polyline']))->toBeTrue()
        ->and($pickup['polyline'])->not->toBe($offer->route_polyline)
        ->and(Polyline::isReadable($dropoff['polyline']))->toBeTrue();
});

it('sends the road on the request preview and the counteroffer options', function () {
    $offer = publishRoadCommute($this->driverToken, $this->commuteId);
    $trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();

    $id = requestSeat(verifiedPassenger('01112223344'), $this->commuteId, ['scheduledTripId' => $trip->id])
        ->assertStatus(201)->json('data.id');

    $preview = test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/seat-requests/{$id}/route-preview")->assertOk()->json('data');
    $options = test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/seat-requests/{$id}/counteroffer-options")->assertOk()->json('data');

    expect(Polyline::isReadable($preview['polyline']))->toBeTrue()
        ->and($options['route']['polyline'])->toBe($offer->route_polyline);
});

it('sends the day road on navigation only while it is recent', function () {
    publishRoadCommute($this->driverToken, $this->commuteId);
    $trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();

    $route = test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/commute-occurrences/{$trip->id}/navigation-route")->assertOk()->json('data');

    expect(Polyline::isReadable($route['polyline']))->toBeTrue();

    ScheduledTrip::query()->whereKey($trip->id)->update([
        'itinerary_version' => 1,
        'itinerary_built_version' => 1,
        'itinerary_built_at' => now()->subDays(31),
    ]);

    $stale = test()->withToken($this->driverToken)
        ->getJson("/api/v1/driver/commute-occurrences/{$trip->id}/navigation-route")->assertOk()->json('data');

    expect($stale['polyline'])->toBeNull()
        ->and($stale['distanceMeters'])->toBeGreaterThan(0);
});

it('fetches the road again once it is older than the refresh period', function () {
    $offer = publishRoadCommute($this->driverToken, $this->commuteId);

    CommuteOffer::query()->whereKey($offer->id)->update(['route_fetched_at' => now()->subDays(8)]);
    $distance = $offer->route_distance_meters;

    $this->artisan('commutes:refresh-roads')->assertSuccessful();

    $fresh = $offer->fresh();

    expect($fresh->route_fetched_at->isToday())->toBeTrue()
        ->and(Polyline::isReadable($fresh->route_polyline))->toBeTrue()
        ->and($fresh->route_distance_meters)->toBe($distance);
});

it('leaves recent roads and drafts alone', function () {
    $offer = publishRoadCommute($this->driverToken, $this->commuteId);
    $fetchedAt = now()->subDays(2)->startOfSecond();
    CommuteOffer::query()->whereKey($offer->id)->update(['route_fetched_at' => $fetchedAt]);

    $draftId = createCommute($this->driverToken, Vehicle::sole()->id)->assertStatus(201)->json('data.id');

    $this->artisan('commutes:refresh-roads')->assertSuccessful();

    expect($offer->fresh()->route_fetched_at->equalTo($fetchedAt))->toBeTrue()
        ->and(CommuteOffer::query()->findOrFail($draftId)->route_fetched_at)->toBeNull();
});
