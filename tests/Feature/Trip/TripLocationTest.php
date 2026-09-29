<?php

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Trip\Events\TripLocationUpdated;
use App\Domains\Trip\Models\TripLocation;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\ValueObjects\TripPosition;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Where the car is (Chapter 8's Live Location, pitfall #46, ERD §23.4).
 *
 * 🔴 Two things are being guarded, and they pull in opposite directions.
 *
 * Performance: writing a row per GPS ping is a hundred inserts a second at five hundred
 * drivers, and the database falls over. So a position becomes current immediately (cache +
 * broadcast) and joins a batch that a scheduled command writes down.
 *
 * Privacy: `trip_locations` is a minute-by-minute record of where a real person was. It
 * exists because a no-show dispute has no other evidence, and for no other reason — so no
 * endpoint returns the trail, the live position dies with the journey, and the rows are
 * deleted after ninety days.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $this->paxToken = verifiedPassenger('01112223344');
    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));
});

/*
|--------------------------------------------------------------------------
| Reporting
|--------------------------------------------------------------------------
*/

it('accepts a batch of positions from the driver', function () {
    underway($this->driverToken, $this->tripId);

    $result = reportPosition($this->driverToken, $this->tripId, [
        position(agoSeconds: 15),
        position(agoSeconds: 10),
        position(agoSeconds: 5),
    ])->assertOk()->json('data');

    expect($result['accepted'])->toBe(3)
        ->and($result['rejected'])->toBe(0);
});

/**
 * 🔴 Pitfall #46. Nothing reaches `trip_locations` on the request path — that is the whole
 * point. A hundred inserts a second is the failure; the passenger's map is fed from the cache
 * and moves either way.
 */
it('writes nothing to the trail on the request itself', function () {
    underway($this->driverToken, $this->tripId);

    reportPosition($this->driverToken, $this->tripId, [position()])->assertOk();

    expect(TripLocation::count())->toBe(0);
});

it('makes the position readable straight away', function () {
    underway($this->driverToken, $this->tripId);

    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.0700, lng: 31.2500)])->assertOk();

    $live = test()->withToken($this->driverToken)->getJson("/api/v1/trips/{$this->tripId}/location")
        ->assertOk()->json('data');

    expect($live['position']['lat'])->toBe(30.07)
        ->and($live['position']['lng'])->toBe(31.25)
        ->and($live['lastLocationAt'])->not->toBeNull()
        ->and($live['isUnderway'])->toBeTrue();
});

/**
 * A batch out of a tunnel arrives in whatever order the client queued it, so "current" has to
 * mean newest by the DEVICE's clock — not last in the array.
 */
it('takes the newest point by the device clock, not by arrival order', function () {
    underway($this->driverToken, $this->tripId);

    reportPosition($this->driverToken, $this->tripId, [
        position(lat: 30.0600, agoSeconds: 5),
        position(lat: 30.0500, agoSeconds: 60),
        position(lat: 30.0400, agoSeconds: 120),
    ])->assertOk();

    expect(test()->withToken($this->driverToken)->getJson("/api/v1/trips/{$this->tripId}/location")
        ->assertOk()->json('data.position.lat'))->toBe(30.06);
});

it('records when the last reading arrived, for dropout detection', function () {
    underway($this->driverToken, $this->tripId);

    reportPosition($this->driverToken, $this->tripId, [position(agoSeconds: 7)])->assertOk();

    $session = TripSession::sole();

    expect($session->last_location_at)->not->toBeNull()
        // The device's moment, not ours: a client showing "4 minutes ago" is measuring from
        // when the phone took the reading.
        ->and($session->last_location_at->diffInSeconds(now(), absolute: true))
        ->toBeGreaterThanOrEqual(5);
});

it('pushes the position to everybody on the run', function () {
    Event::fake([TripLocationUpdated::class]);

    underway($this->driverToken, $this->tripId);

    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.0611)])->assertOk();

    Event::assertDispatched(TripLocationUpdated::class, function (TripLocationUpdated $event) {
        return $event->position->lat === 30.0611
            // 🔒 A private channel per run. A public one would be a live feed of where
            // identifiable people are, readable by anybody who guessed a trip id.
            && $event->broadcastOn()->name === 'private-trip.'.$event->tripSessionId;
    });
});

it('sends only the position on the channel, and nothing about who is in the car', function () {
    underway($this->driverToken, $this->tripId);

    $event = new TripLocationUpdated('01ABC', TripPosition::fromArray(position()));

    // No passenger list, no driver name, no booking ids — a channel payload is the easiest
    // thing in a system to end up logged by a proxy.
    expect(array_keys($event->broadcastWith()))
        ->toEqualCanonicalizing(['lat', 'lng', 'recordedAt', 'accuracyMeters', 'speedKmh']);
});

/*
|--------------------------------------------------------------------------
| What the server refuses to believe
|--------------------------------------------------------------------------
*/

/**
 * 🔴 `recordedAt` is the device's clock, which is not ours. A point timestamped an hour from
 * now is a broken clock or a fabrication, and either would poison the trail a dispute is read
 * from.
 */
it('drops a point from the future', function () {
    underway($this->driverToken, $this->tripId);

    $future = position();
    $future['recordedAt'] = now()->addHour()->toIso8601String();

    $result = reportPosition($this->driverToken, $this->tripId, [position(), $future])->assertOk()->json('data');

    // Filtered, not refused: a batch of six where one is bad should deliver five. A 422
    // would make the client retry the whole batch forever.
    expect($result['accepted'])->toBe(1)
        ->and($result['rejected'])->toBe(1);
});

it('allows a small clock skew, because phone clocks drift', function () {
    underway($this->driverToken, $this->tripId);

    $slightlyAhead = position();
    $slightlyAhead['recordedAt'] = now()->addSeconds(20)->toIso8601String();

    expect(reportPosition($this->driverToken, $this->tripId, [$slightlyAhead])->assertOk()->json('data.accepted'))
        ->toBe(1);
});

it('drops a point from before the run began', function () {
    underway($this->driverToken, $this->tripId);

    // Somebody's yesterday would otherwise be appended to this morning's evidence.
    $stale = position();
    $stale['recordedAt'] = now()->subDays(2)->toIso8601String();

    expect(reportPosition($this->driverToken, $this->tripId, [$stale])->assertOk()->json('data.rejected'))
        ->toBe(1);
});

/**
 * A phone with no satellite fix guesses from cell towers and can be kilometres out. Drawing
 * that puts the car in the wrong district on a passenger's map.
 */
it('drops a point the phone was not confident about', function () {
    underway($this->driverToken, $this->tripId);

    $result = reportPosition($this->driverToken, $this->tripId, [
        position(accuracy: 15),
        position(accuracy: 4000),
    ])->assertOk()->json('data');

    expect($result['accepted'])->toBe(1)
        ->and($result['rejected'])->toBe(1);
});

it('accepts a point from a phone that reported no accuracy at all', function () {
    underway($this->driverToken, $this->tripId);

    // Not every platform reports it, and refusing those would lose whole devices.
    expect(reportPosition($this->driverToken, $this->tripId, [position(accuracy: null)])
        ->assertOk()->json('data.accepted'))->toBe(1);
});

it('caps how many points one request may carry', function () {
    underway($this->driverToken, $this->tripId);

    // The cap is what stops one request queueing a hundred thousand rows for insert.
    $points = array_fill(0, 200, position());

    reportPosition($this->driverToken, $this->tripId, $points)->assertStatus(422);
});

it('refuses a position on a run that has not left', function () {
    runLeavingIn($this->tripId, 10);
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/start")->assertStatus(201);

    // A phone sitting on a kitchen table.
    reportPosition($this->driverToken, $this->tripId, [position()])
        ->assertStatus(409)
        ->assertJsonPath('error.fields.tripStatus.0', 'PREPARING');
});

it('refuses a position on a run that has finished', function () {
    underway($this->driverToken, $this->tripId);
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    // 🔒 Somebody's evening, not this trip.
    reportPosition($this->driverToken, $this->tripId, [position()])
        ->assertStatus(409)
        ->assertJsonPath('error.fields.tripStatus.0', 'COMPLETED');
});

/*
|--------------------------------------------------------------------------
| Who may report and who may watch
|--------------------------------------------------------------------------
*/

it('never lets a passenger report a position', function () {
    underway($this->driverToken, $this->tripId);

    reportPosition($this->paxToken, $this->tripId, [position()])->assertStatus(404);
});

it('lets a passenger on the run watch the car', function () {
    underway($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.0666)])->assertOk();

    expect(test()->withToken($this->paxToken)->getJson("/api/v1/trips/{$this->tripId}/location")
        ->assertOk()->json('data.position.lat'))->toBe(30.0666);
});

/**
 * 🔒 Narrower than the rule for reading the trip itself, which admits a completed booking so
 * the dispute window works. A position is different: somebody who has finished their journey
 * has no reason to keep watching the car, and where it goes next is the driver's home.
 */
it('stops showing the car to a passenger who cancelled', function () {
    underway($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position()])->assertOk();

    test()->withToken($this->paxToken)->patchJson("/api/v1/bookings/{$this->bookingId}/cancel")->assertOk();

    test()->withToken($this->paxToken)->getJson("/api/v1/trips/{$this->tripId}/location")->assertStatus(404);
});

it('hides the car from somebody with no seat on it', function () {
    underway($this->driverToken, $this->tripId);

    fakeOtpSender();
    $stranger = verifiedPassenger('01223339999', device: 'pax-2');

    test()->withToken($stranger)->getJson("/api/v1/trips/{$this->tripId}/location")->assertStatus(404);
});

it('requires a token', function () {
    test()->withoutToken()->getJson("/api/v1/trips/{$this->tripId}/location")->assertStatus(401);
    test()->withoutToken()->postJson("/api/v1/trips/{$this->tripId}/location")->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| The live position dies with the journey
|--------------------------------------------------------------------------
*/

/**
 * 🔒 Left behind, "where is the car" would keep answering with wherever it was when everybody
 * got out — an office car park at the same time every weekday, and then the driver's street.
 */
it('forgets the live position when the run is completed', function () {
    underway($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position()])->assertOk();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    // 404 on the endpoint, because a completed run is not one a passenger may watch — and the
    // driver's own read answers with nothing rather than a stale dot.
    expect(test()->withToken($this->driverToken)->getJson("/api/v1/trips/{$this->tripId}/location")
        ->assertOk()->json('data.position'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The trail, and its retention
|--------------------------------------------------------------------------
*/

it('writes the buffered points down when the flush runs', function () {
    underway($this->driverToken, $this->tripId);

    reportPosition($this->driverToken, $this->tripId, [
        position(agoSeconds: 20),
        position(agoSeconds: 10),
    ])->assertOk();

    expect(TripLocation::count())->toBe(0);

    test()->artisan('trips:flush-locations')->assertSuccessful();

    expect(TripLocation::count())->toBe(2)
        // `insert()` bypasses the model, so `HasUlids` never runs — the ids have to be
        // generated by hand or the rows arrive with no primary key.
        ->and(TripLocation::query()->whereNull('id')->count())->toBe(0);
});

/**
 * 🔒 The retention date is stamped on the row as it is created (ERD §23.4: 90 days). A
 * boundary enforced by a query that has to remember to calculate it is not enforced.
 */
it('stamps every row with its retention date', function () {
    underway($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position()])->assertOk();

    test()->artisan('trips:flush-locations')->assertSuccessful();

    $row = TripLocation::sole();

    expect($row->purge_after)->not->toBeNull()
        ->and($row->purge_after->toDateString())->toBe(now()->addDays(90)->toDateString());
});

it('does not write the same points twice when the flush runs again', function () {
    underway($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position()])->assertOk();

    test()->artisan('trips:flush-locations')->assertSuccessful();
    test()->artisan('trips:flush-locations')->assertSuccessful();

    expect(TripLocation::count())->toBe(1);
});

it('keeps the device clock on the stored row, not the arrival time', function () {
    underway($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position(agoSeconds: 45)])->assertOk();

    test()->artisan('trips:flush-locations')->assertSuccessful();

    expect(TripLocation::sole()->recorded_at->diffInSeconds(now(), absolute: true))
        ->toBeGreaterThanOrEqual(40);
});

/**
 * 🔒 Not housekeeping. Past ninety days the record has no purpose left, and a record with no
 * purpose is something to be subpoenaed, breached or quietly repurposed — none of which was
 * consented to.
 */
it('deletes trails past their retention date', function () {
    underway($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position()])->assertOk();
    test()->artisan('trips:flush-locations')->assertSuccessful();

    // Moved on the row rather than travelling ninety days, which would expire the token.
    TripLocation::query()->update(['purge_after' => now()->subDay()->toDateString()]);

    test()->artisan('trips:purge-locations')->assertSuccessful();

    expect(TripLocation::count())->toBe(0);
});

it('leaves a trail that is still inside its retention', function () {
    underway($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position()])->assertOk();
    test()->artisan('trips:flush-locations')->assertSuccessful();

    test()->artisan('trips:purge-locations')->assertSuccessful();

    expect(TripLocation::count())->toBe(1);
});

/**
 * 🔴 Rows with no retention date are reported rather than deleted or ignored. Deleting them
 * would destroy evidence on a guess about its age; ignoring them would leave a hole in the
 * retention promise that nobody is looking at.
 */
it('warns about a row with no retention date instead of guessing', function () {
    underway($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position()])->assertOk();
    test()->artisan('trips:flush-locations')->assertSuccessful();

    TripLocation::query()->update(['purge_after' => null]);

    test()->artisan('trips:purge-locations')
        ->expectsOutputToContain('outside the 90-day promise')
        ->assertSuccessful();

    expect(TripLocation::count())->toBe(1);
});

it('does nothing and says so when there is nothing buffered', function () {
    test()->artisan('trips:flush-locations')->assertSuccessful();

    expect(TripLocation::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| No endpoint returns the trail
|--------------------------------------------------------------------------
*/

/**
 * 🔒 The rule the whole table depends on. `trip_locations` is a minute-by-minute record of
 * where a real person was, kept because a no-show dispute has no other evidence — and read by
 * support through the dashboard, never handed to a client.
 *
 * Asserted against the ROUTE LIST rather than by calling endpoints, because the risk is a
 * future route being added without anybody thinking about it.
 */
it('publishes no endpoint that returns the GPS trail', function () {
    $suspicious = array_filter(
        registeredV1Paths(),
        fn (string $path) => str_contains($path, 'locations')
            || str_contains($path, 'trail')
            || str_contains($path, 'history'),
    );

    expect($suspicious)->toBeEmpty(
        "These /v1 routes look like they expose a GPS trail: \n- ".implode("\n- ", $suspicious)
        ."\n\n`trip_locations` is a record of where real people were, kept only for disputes. "
        .'If a client genuinely needs it, that is a product decision about privacy, not a route.'
    );
});
