<?php

use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Chapter 4 end to end: a verified driver publishes a commute passengers can
 * safely join.
 *
 * The reference journey is the Bible's — Rehab City to Smart Village, Sunday to
 * Thursday, 07:05.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->token = approvedDriver();
    $this->vehicleId = Vehicle::sole()->id;
});

it('opens a commute as a draft and says what is still missing', function () {
    $response = createCommute($this->token, $this->vehicleId)->assertStatus(201);

    expect($response->json('data.status'))->toBe('DRAFT')
        ->and($response->json('data.missing'))->toContain('origin', 'destination', 'schedule')
        // A draft has no route yet: computing one on every edit would bill a
        // provider for journeys nobody published.
        ->and($response->json('data.routePolyline'))->toBeNull();
});

it('cannot be told it is already published by the request that creates it', function () {
    createCommute($this->token, $this->vehicleId, ['status' => 'published'])->assertStatus(201);

    expect(CommuteOffer::sole()->status)->toBe(CommuteOfferStatus::Draft);
});

/**
 * Chapter 4 §5: seats offered cannot exceed vehicle capacity. `vehicles.seats`
 * counts the driver, so what can be offered is one fewer.
 */
it('refuses more seats than the vehicle has, not counting the driver', function () {
    // The test vehicle seats 5, so 4 is the most that can be offered.
    createCommute($this->token, $this->vehicleId, ['seatsTotal' => 5])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['seatsTotal']]]);

    createCommute($this->token, $this->vehicleId, ['seatsTotal' => 4])->assertStatus(201);
});

/**
 * Chapter 4 §2: the vehicle "must be approved and active".
 */
it('refuses a vehicle that is not approved and active', function () {
    Vehicle::sole()->forceFill([
        'verification_status' => VehicleVerificationStatus::Suspended->value,
    ])->save();

    createCommute($this->token, $this->vehicleId)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_VEHICLE_UNAVAILABLE');
});

it('answers 404 for another driver vehicle', function () {
    fakeOtpSender();
    approvedDriver(phone: '01112223344', devicePublicId: 'dev-2', seed: 2);

    $theirs = Vehicle::query()->where('plate_normalized', 'ABC1236')->sole();

    // 404, not 403: a 403 would confirm the vehicle id exists.
    createCommute($this->token, $theirs->id)->assertStatus(404);
});

/**
 * Chapter 4 §3: "origin cannot equal destination" — compared by distance,
 * because two pins a few metres apart are the same place for a journey.
 */
it('refuses a route whose origin and destination are the same place', function () {
    $id = createCommute($this->token, $this->vehicleId)->assertStatus(201)->json('data.id');

    saveRoute($this->token, $id, [
        'destination' => ['lat' => 30.05945, 'lng' => 31.49135],
    ])->assertStatus(422)->assertJsonPath('error.code', 'COMMUTE_ROUTE_INVALID');
});

it('stores the route with origin first and destination last', function () {
    $id = createCommute($this->token, $this->vehicleId)->assertStatus(201)->json('data.id');

    $locations = saveRoute($this->token, $id, [
        'pickups' => [['lat' => 30.0650, 'lng' => 31.2300, 'address' => 'Ring Road']],
    ])->assertOk()->json('data.locations');

    expect(collect($locations)->pluck('type')->all())->toBe(['origin', 'pickup', 'destination'])
        ->and($locations[0]['sequence'])->toBe(0)
        ->and($locations[2]['sequence'])->toBe(999);
});

/**
 * Chapter 4 §3: "each pickup must be on the route within tolerance" — and the
 * tolerance is the driver's own stated detour, not a fixed number.
 */
it('refuses a pickup further out of the way than the driver allows', function () {
    $id = createCommute($this->token, $this->vehicleId, ['maxDetourMinutes' => 5])
        ->assertStatus(201)->json('data.id');

    saveRoute($this->token, $id, [
        'pickups' => [['lat' => 30.6000, 'lng' => 31.9000]],
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_ROUTE_INVALID')
        ->assertJsonStructure(['error' => ['fields' => ['pickups.0']]]);
});

it('accepts the same pickup when the driver allows a longer detour', function () {
    $tight = createCommute($this->token, $this->vehicleId, ['maxDetourMinutes' => 2])
        ->assertStatus(201)->json('data.id');

    // ~10km north of the direct line, which is about a 7-minute detour: inside a
    // 30-minute tolerance and well outside a 2-minute one. A point nearer the
    // line would pass both and prove nothing.
    $pickup = ['pickups' => [['lat' => 30.1500, 'lng' => 31.2000]]];

    saveRoute($this->token, $tight, $pickup)->assertStatus(422);

    $this->withToken($this->token)->patchJson("/api/v1/commutes/{$tight}", ['maxDetourMinutes' => 30])
        ->assertOk();

    saveRoute($this->token, $tight, $pickup)->assertOk();
});

it('refuses more stops than a commute may have', function () {
    $id = createCommute($this->token, $this->vehicleId, ['maxDetourMinutes' => 30])
        ->assertStatus(201)->json('data.id');

    $tooMany = array_fill(
        0,
        (int) config('rafeeq.commute.max_pickup_points') + 1,
        ['lat' => 30.0650, 'lng' => 31.2300],
    );

    saveRoute($this->token, $id, ['pickups' => $tooMany])->assertStatus(422);
});

/**
 * Chapter 4 §4: "start date cannot be in the past" and "no infinite commutes".
 */
it('refuses a schedule that starts in the past or runs too far ahead', function () {
    $id = createCommute($this->token, $this->vehicleId)->assertStatus(201)->json('data.id');

    saveSchedule($this->token, $id, ['startDate' => CarbonImmutable::yesterday()->toDateString()])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['startDate']]]);

    saveSchedule($this->token, $id, [
        'endDate' => CarbonImmutable::today()->addYears(3)->toDateString(),
    ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['endDate']]]);
});

it('refuses a schedule with no days chosen', function () {
    $id = createCommute($this->token, $this->vehicleId)->assertStatus(201)->json('data.id');

    saveSchedule($this->token, $id, ['daysMask' => 0])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['daysMask']]]);
});

/**
 * A one-time commute uses the same shape as a recurring one — a single day in
 * the mask, start and end on the same date — so nothing downstream special-cases
 * it.
 */
it('collapses a one-time commute to a single date', function () {
    $id = createCommute($this->token, $this->vehicleId, ['commuteType' => 'one_time'])
        ->assertStatus(201)->json('data.id');

    saveRoute($this->token, $id)->assertOk();

    $date = CarbonImmutable::tomorrow();

    $schedule = saveSchedule($this->token, $id, [
        'daysMask' => 0,
        'startDate' => $date->toDateString(),
        'endDate' => $date->addMonth()->toDateString(),
    ])->assertOk()->json('data.schedule');

    expect($schedule['startDate'])->toBe($date->toDateString())
        ->and($schedule['endDate'])->toBe($date->toDateString())
        ->and($schedule['daysMask'])->toBe(DaysMask::bitForDate($date));

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    expect(ScheduledTrip::count())->toBe(1);
});

it('refuses to publish while anything is missing', function () {
    $id = createCommute($this->token, $this->vehicleId)->assertStatus(201)->json('data.id');

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_INCOMPLETE')
        ->assertJsonPath('error.fields.missing', ['origin', 'destination', 'schedule']);
});

it('publishes: computes the route once, writes the search box, and generates days', function () {
    $id = readyCommute($this->token, $this->vehicleId);

    $data = $this->withToken($this->token)
        ->postJson("/api/v1/commutes/{$id}/publish")->assertOk()->json('data');

    expect($data['status'])->toBe('PUBLISHED')
        ->and($data['publishedAt'])->not->toBeNull()
        ->and($data['routePolyline'])->not->toBeNull()
        ->and($data['routeDistanceMeters'])->toBeGreaterThan(0)
        ->and($data['upcomingTrips'])->not->toBeEmpty();

    $offer = CommuteOffer::sole();

    // The search box is what makes an offer findable at all, so it must be set.
    expect($offer->bbox_min_lat)->not->toBeNull()
        ->and((float) $offer->bbox_min_lat)->toBeLessThan(30.0594)
        ->and((float) $offer->bbox_max_lng)->toBeGreaterThan(31.4913);
});

it('never exposes the search box, which is a query plan rather than information', function () {
    $id = readyCommute($this->token, $this->vehicleId);

    $body = $this->withToken($this->token)
        ->postJson("/api/v1/commutes/{$id}/publish")->assertOk()->getContent();

    expect($body)->not->toContain('bbox');
});

it('refuses to publish twice', function () {
    $id = readyCommute($this->token, $this->vehicleId);

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$id}/publish")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_INVALID_TRANSITION');
});

/**
 * A draft can sit for weeks. Eligibility is therefore re-checked at publish,
 * not only when the draft was opened.
 */
it('refuses to publish once the licence has lapsed', function () {
    $id = readyCommute($this->token, $this->vehicleId);

    DriverProfile::sole()
        ->forceFill(['licence_expiry' => CarbonImmutable::yesterday()->toDateString()])->save();

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'DRIVER_LICENCE_EXPIRED');
});

it('refuses to publish once the vehicle has been suspended', function () {
    $id = readyCommute($this->token, $this->vehicleId);

    Vehicle::sole()->forceFill([
        'verification_status' => VehicleVerificationStatus::Suspended->value,
    ])->save();

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_VEHICLE_UNAVAILABLE');
});

it('locks the route and schedule once published, but still allows the terms to change', function () {
    $id = readyCommute($this->token, $this->vehicleId);

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    // The route and schedule are what passengers booked specific days on.
    saveRoute($this->token, $id)->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_NOT_EDITABLE');
    saveSchedule($this->token, $id)->assertStatus(409);

    // Price and preferences are fair game: they apply to future days only.
    $this->withToken($this->token)->patchJson("/api/v1/commutes/{$id}", [
        'pricePerSeatPiastres' => 9000,
        'rules' => ['nonsmoking' => true, 'quiet' => true],
    ])->assertOk()
        ->assertJsonPath('data.pricePerSeatPiastres', 9000)
        ->assertJsonPath('data.rules.nonsmoking', true);
});

it('keeps the price a booked day was generated with', function () {
    $id = readyCommute($this->token, $this->vehicleId);

    $this->withToken($this->token)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    $before = ScheduledTrip::orderBy('trip_date')->first()->price_snapshot_piastres;

    $this->withToken($this->token)->patchJson("/api/v1/commutes/{$id}", [
        'pricePerSeatPiastres' => 11000,
    ])->assertOk();

    expect(ScheduledTrip::orderBy('trip_date')->first()->price_snapshot_piastres)->toBe($before);
});

it('answers 404 for another driver commute', function () {
    $mine = readyCommute($this->token, $this->vehicleId);

    fakeOtpSender();
    $otherToken = approvedDriver(phone: '01112223344', devicePublicId: 'dev-2', seed: 2);

    $this->withToken($otherToken)->getJson("/api/v1/commutes/{$mine}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $this->withToken($otherToken)->postJson("/api/v1/commutes/{$mine}/publish")->assertStatus(404);

    expect(CommuteOffer::findOrFail($mine)->status)->toBe(CommuteOfferStatus::Draft);
});

it('requires a verified identity for every commute route', function (string $method, string $uri) {
    fakeOtpSender();
    $token = signIn(phone: '01223334455', devicePublicId: 'unverified')['session']['accessToken'];
    completeBasicProfile($token);

    $this->withToken($token)->json($method, $uri)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED');
})->with([
    ['GET', '/api/v1/commutes'],
    ['POST', '/api/v1/commutes'],
]);
