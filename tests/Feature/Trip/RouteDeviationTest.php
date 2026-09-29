<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\Support\Polyline;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Storage;

/**
 * "Route changed" (screen 39) — noticing when a run has left its published route.
 *
 * 🔴 Both mistakes this can make are expensive, and that shapes every decision here. Fire too
 * easily and it goes off on roadworks, a one-way system, or a phone's own error — and an alert that
 * cries wolf is one everybody learns to dismiss, including on the morning it matters. Fire too
 * rarely and a car genuinely going the wrong way is never flagged.
 *
 * So: a generous threshold, measured to the route's LINE rather than its vertices, and checked only
 * while the run is actually driving to the destination.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
});

/**
 * Drives the run to `IN_PROGRESS`, which is the only state deviation is checked in.
 */
function driving(string $driverToken, string $tripId): void
{
    underway($driverToken, $tripId);

    test()->withToken($driverToken)
        ->postJson("/api/v1/trips/{$tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
}

/*
|--------------------------------------------------------------------------
| The measurement itself
|--------------------------------------------------------------------------
*/

/**
 * 🔴 The reason this is measured to segments and not to vertices. A provider's polyline has a
 * vertex every few hundred metres on a straight stretch; measuring to the nearest vertex would
 * report a car driving exactly down the middle of the road as hundreds of metres off it.
 */
it('measures to the route line, not to its corners', function () {
    $geo = app(GeoQueryEngine::class);

    // Two points a long way apart, so the midpoint is far from both vertices.
    $route = new Route(
        polyline: Polyline::encode([
            new Coordinate(30.0000, 31.0000),
            new Coordinate(30.0000, 31.2000),
        ]),
        distance: Distance::fromKilometres(19),
        durationSeconds: 2400,
    );

    // Exactly on the line, halfway along it — about 9.6km from either end.
    $onTheLine = $geo->distanceFromRoute($route, new Coordinate(30.0000, 31.1000));

    expect($onTheLine->metres)->toBeLessThan(5);
});

/**
 * The projection is clamped to the segment. Unclamped, it would measure to the infinite line
 * through the two vertices — so a car sitting a long way beyond the end of a route would be
 * reported as on it.
 */
it('does not treat a point beyond the end of the route as on it', function () {
    $geo = app(GeoQueryEngine::class);

    $route = new Route(
        polyline: Polyline::encode([
            new Coordinate(30.0000, 31.0000),
            new Coordinate(30.0000, 31.0100),
        ]),
        distance: Distance::fromKilometres(1),
        durationSeconds: 120,
    );

    // On the same latitude, but well past the destination.
    $beyond = $geo->distanceFromRoute($route, new Coordinate(30.0000, 31.2000));

    expect($beyond->metres)->toBeGreaterThan(15_000);
});

it('measures a point off to the side', function () {
    $geo = app(GeoQueryEngine::class);

    $route = new Route(
        polyline: Polyline::encode([
            new Coordinate(30.0000, 31.0000),
            new Coordinate(30.0000, 31.1000),
        ]),
        distance: Distance::fromKilometres(9.6),
        durationSeconds: 1200,
    );

    // 0.01° of latitude north of the line is about 1.1km.
    $aside = $geo->distanceFromRoute($route, new Coordinate(30.0100, 31.0500));

    expect($aside->metres)->toBeGreaterThan(1000)
        ->and($aside->metres)->toBeLessThan(1250);
});

/**
 * "We do not know" must not read as "she has driven into the desert" — a huge number would trip
 * every threshold in the system.
 */
it('answers zero for a route with no points at all', function () {
    $geo = app(GeoQueryEngine::class);

    $empty = new Route(polyline: '', distance: Distance::fromMetres(0), durationSeconds: 0);

    expect($geo->distanceFromRoute($empty, new Coordinate(30.0654, 31.2314))->metres)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Detection on a live run
|--------------------------------------------------------------------------
*/

it('says nothing about a run following its route', function () {
    driving($this->driverToken, $this->tripId);

    // The reference route runs Rehab → Smart Village; this point is on it.
    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.0654, lng: 31.2314)])->assertOk();

    $session = TripSession::sole();

    expect($session->deviation_detected_at)->toBeNull()
        ->and($session->deviation_distance_meters)->toBeNull();
});

it('records a run that has gone a long way off its route', function () {
    driving($this->driverToken, $this->tripId);

    // Far north of the corridor.
    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.4000, lng: 31.2314)])->assertOk();

    $session = TripSession::sole();

    expect($session->deviation_detected_at)->not->toBeNull()
        ->and($session->deviation_distance_meters)->toBeGreaterThan(1000);
});

/**
 * 🔴 The two useful facts are when it started and how far it went. Overwriting the moment on every
 * ping would answer "when did this begin" with "a second ago", for a diversion that started twenty
 * minutes back.
 */
it('keeps the first moment and the worst distance', function () {
    driving($this->driverToken, $this->tripId);

    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.4000, lng: 31.2314)])->assertOk();

    $firstDetection = TripSession::sole()->deviation_detected_at;
    $firstDistance = TripSession::sole()->deviation_distance_meters;

    test()->travelTo(now()->addMinutes(4));

    // Further away still.
    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.7000, lng: 31.2314)])->assertOk();

    $session = TripSession::sole();

    expect($session->deviation_detected_at->toIso8601String())->toBe($firstDetection->toIso8601String())
        ->and($session->deviation_distance_meters)->toBeGreaterThan($firstDistance);
});

it('does not shrink the worst distance when the car comes back towards the route', function () {
    driving($this->driverToken, $this->tripId);

    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.7000, lng: 31.2314)])->assertOk();
    $worst = TripSession::sole()->deviation_distance_meters;

    // Still off-route, but closer than before.
    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.4000, lng: 31.2314)])->assertOk();

    // How far it went is a fact about the run, not about where it is now.
    expect(TripSession::sole()->deviation_distance_meters)->toBe($worst);
});

/**
 * 🔴 The judgement that stops this alert becoming noise. On the way to collect people, being off
 * the direct line is the job — a driver detouring to an approved custom pickup added after the
 * route was published is doing exactly what she agreed to, and flagging her would make the alert
 * fire most often on the drivers being most accommodating.
 */
it('says nothing while the driver is still collecting people', function () {
    // EN_ROUTE, not IN_PROGRESS.
    underway($this->driverToken, $this->tripId);

    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.4000, lng: 31.2314)])->assertOk();

    expect(TripSession::sole()->deviation_detected_at)->toBeNull();
});

it('says nothing at a pickup either', function () {
    underway($this->driverToken, $this->tripId);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'AT_PICKUP'])->assertOk();

    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.4000, lng: 31.2314)])->assertOk();

    expect(TripSession::sole()->deviation_detected_at)->toBeNull();
});

/**
 * Silence rather than a guess. A commute with no stored polyline has nothing to measure against,
 * and treating "no route" as "off route" would flag every run on a commute whose publish never
 * computed one.
 */
it('says nothing when there is no published route to measure against', function () {
    driving($this->driverToken, $this->tripId);

    CommuteOffer::query()->whereKey($this->commuteId)->update(['route_polyline' => null]);

    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.7000, lng: 31.2314)])->assertOk();

    expect(TripSession::sole()->deviation_detected_at)->toBeNull();
});

/**
 * 🔴 Where the line sits is something only real mornings will settle, so it has to move without a
 * deploy: fire too easily and everybody learns to dismiss the alert.
 */
it('follows the threshold from settings rather than from code', function () {
    driving($this->driverToken, $this->tripId);

    // A point that is off-route but comfortably inside the default kilometre.
    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.0700, lng: 31.2314)])->assertOk();

    expect(TripSession::sole()->deviation_detected_at)->toBeNull();

    PlatformSetting::query()->create([
        'setting_key' => 'trip.deviation_threshold_meters',
        'setting_value' => 50,
        'value_type' => 'integer',
        'description' => 'A much tighter threshold',
    ]);

    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.0700, lng: 31.2314)])->assertOk();

    expect(TripSession::sole()->deviation_detected_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| What the screen receives
|--------------------------------------------------------------------------
*/

it('tells the driver and the passengers that the run went off route', function () {
    $paxToken = verifiedPassenger('01112223344');
    approveSeat($this->driverToken, requestSeat($paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));

    driving($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.4000, lng: 31.2314)])->assertOk();

    $seen = test()->withToken($paxToken)->getJson("/api/v1/trips/{$this->tripId}")
        ->assertOk()->json('data');

    expect($seen['deviationDetectedAt'])->not->toBeNull()
        ->and($seen['deviationDistanceMeters'])->toBeGreaterThan(1000);
});

/**
 * Null on the overwhelming majority of runs, which is the point: this is an exception report, not
 * a measurement. A client showing "0 m off route" on every normal morning would be showing noise.
 */
it('leaves both fields null on a normal run', function () {
    driving($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position()])->assertOk();

    $seen = test()->withToken($this->driverToken)->getJson("/api/v1/trips/{$this->tripId}")
        ->assertOk()->json('data');

    expect($seen['deviationDetectedAt'])->toBeNull()
        ->and($seen['deviationDistanceMeters'])->toBeNull();
});
