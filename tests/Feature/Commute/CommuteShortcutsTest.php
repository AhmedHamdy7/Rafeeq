<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteSchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * `Duplicate Commute` and `Add Return Commute` (Feature 03 v5 §4 and §5).
 *
 * > *"`Add Return Commute` prefills reverse route but driver reviews independently;
 * > `Duplicate Commute` copies settings only. **Explain neither shortcut copies
 * > passengers/bookings/waiting list.**"*
 *
 * 🔴 Most of this file is about what must not be copied. Every booking, seat request and
 * waiting-list row in the system hangs off the commute being duplicated, so the natural
 * implementation — copy the row and its relations — would carry real passengers into a journey they
 * never agreed to, and the first they would hear of it is a car arriving for them.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->vehicleId = Vehicle::sole()->id;

    // A full journey: route with an advertised stop, a per-weekday schedule, preferences.
    $this->commuteId = createCommute($this->driverToken, $this->vehicleId, [
        'seatsTotal' => 3,
        'pricePerSeatPiastres' => 8000,
        'audience' => 'women_only',
        'allowsCustomPickup' => true,
    ])->assertStatus(201)->json('data.id');

    // House rules arrive through the revise endpoint, as a keyed map.
    test()->withToken($this->driverToken)
        ->patchJson("/api/v1/commutes/{$this->commuteId}", [
            'rules' => ['nonsmoking' => true, 'quiet' => true],
        ])->assertOk();

    saveRoute($this->driverToken, $this->commuteId, [
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913, 'address' => 'Rehab Gate 2'],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716, 'address' => 'Smart Village B6'],
        // Two advertised stops along the way, in road order.
        'pickups' => [
            ['lat' => 30.0624, 'lng' => 31.3614, 'address' => 'Ring Road, Rehab exit'],
            ['lat' => 30.0684, 'lng' => 31.1015, 'address' => 'Mehwar, Sheikh Zayed'],
        ],
    ])->assertOk();

    saveSchedule($this->driverToken, $this->commuteId, [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'departureTime' => '07:05:00',
        'promisedArrivalTime' => '08:00:00',
        'startDate' => CarbonImmutable::tomorrow()->toDateString(),
        'endDate' => CarbonImmutable::today()->addMonths(3)->toDateString(),
        // One cheaper, roomier Thursday, so the per-day table has something worth carrying.
        'perDay' => [
            (string) DaysMask::THURSDAY => [
                'departureTime' => '07:30:00',
                'pricePerSeatPiastres' => 6000,
                'seatsTotal' => 2,
            ],
        ],
    ])->assertOk();
});

/**
 * Publishes it and seats somebody, so the duplicate has real passengers to not copy.
 */
function commuteWithAPassenger(string $driverToken, string $commuteId): array
{
    test()->flushHeaders()->withToken($driverToken)
        ->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $trip = ScheduledTrip::query()->where('commute_offer_id', $commuteId)->orderBy('trip_date')->firstOrFail();

    $paxToken = verifiedPassenger('01112223344', device: 'pax-1');

    $requestId = requestSeat($paxToken, $commuteId, ['scheduledTripId' => $trip->id])
        ->assertStatus(201)->json('data.id');

    return ['bookingId' => approveSeat($driverToken, $requestId), 'tripId' => $trip->id];
}

/*
|--------------------------------------------------------------------------
| Duplicate
|--------------------------------------------------------------------------
*/

it('copies the terms, the route and the weekly pattern into a draft', function () {
    $copy = test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/duplicate")
        ->assertStatus(201)
        // 🔴 A draft. A shortcut that published would put a journey on sale nobody reviewed.
        ->assertJsonPath('data.status', 'DRAFT')
        ->assertJsonPath('data.seatsTotal', 3)
        ->assertJsonPath('data.pricePerSeatPiastres', 8000)
        ->assertJsonPath('data.audience', 'women_only')
        ->assertJsonPath('data.direction', 'to_work')
        ->json('data');

    expect($copy['id'])->not->toBe($this->commuteId);

    $draft = CommuteOffer::query()->whereKey($copy['id'])->with(['locations', 'rules', 'weekdaySchedules'])->sole();
    $source = CommuteOffer::query()->whereKey($this->commuteId)->with(['locations', 'rules', 'weekdaySchedules'])->sole();

    // The same four points, in the same order and the same roles.
    expect($draft->locations->sortBy('sequence')->pluck('type')->all())
        ->toBe($source->locations->sortBy('sequence')->pluck('type')->all())
        ->and($draft->locations->sortBy('sequence')->pluck('address')->all())
        ->toBe($source->locations->sortBy('sequence')->pluck('address')->all());

    // Her preferences, and the cheaper Thursday she set up by hand.
    expect($draft->rules->pluck('rule_key')->map->value->sort()->values()->all())
        ->toBe($source->rules->pluck('rule_key')->map->value->sort()->values()->all())
        ->and($draft->weekdaySchedules->firstWhere('weekday_bit', DaysMask::THURSDAY)->price_per_seat_piastres)
        ->toBe(6000)
        ->and($draft->weekdaySchedules->firstWhere('weekday_bit', DaysMask::THURSDAY)->departure_time)
        ->toBe('07:30:00');
});

/**
 * 🔴 The headline requirement, and the one a loop over relations would break.
 */
it('copies nobody', function () {
    $live = commuteWithAPassenger($this->driverToken, $this->commuteId);

    $copyId = test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/duplicate")
        ->assertStatus(201)->json('data.id');

    // The passenger is still on the original, and only on the original.
    expect(Booking::query()->whereKey($live['bookingId'])->sole()->scheduled_trip_id)->toBe($live['tripId'])
        ->and(SeatRequest::query()->where('commute_offer_id', $copyId)->count())->toBe(0)
        ->and(ScheduledTrip::query()->where('commute_offer_id', $copyId)
            ->whereIn('id', Booking::query()->select('scheduled_trip_id'))->count())->toBe(0);

    // And no days at all yet: a draft generates none until it is published.
    expect(ScheduledTrip::query()->where('commute_offer_id', $copyId)->count())->toBe(0);
});

/**
 * ⚠️ `corridor_id` is computed at publish from the route. Copying it would attach a label that may
 * not describe the new journey — and for a reversed one, almost certainly does not.
 */
it('carries no corridor and no measured route', function () {
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    /*
     * Set by hand rather than asserted as a side effect of publishing: a corridor is only
     * attached when one matches, and the measured route depends on the geo engine. Forcing both
     * makes this a test about what the SHORTCUT carries, which is the question.
     */
    CommuteOffer::query()->whereKey($this->commuteId)->update([
        'corridor_id' => Corridor::factory()->create()->id,
        'route_polyline' => 'abc123',
        'route_distance_meters' => 42_000,
        'route_duration_seconds' => 3_600,
    ]);

    $copyId = test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/duplicate")
        ->assertStatus(201)->json('data.id');

    $draft = CommuteOffer::query()->whereKey($copyId)->sole();

    expect($draft->corridor_id)->toBeNull()
        ->and($draft->route_distance_meters)->toBeNull()
        ->and($draft->route_polyline)->toBeNull()
        ->and($draft->published_at)->toBeNull();
});

/**
 * ⚠️ A schedule may not start in the past, so a literal copy of a window that has begun would be a
 * draft she cannot publish — with nothing on the screen explaining why.
 */
it('moves a window that has already started to tomorrow', function () {
    CommuteSchedule::query()->where('commute_offer_id', $this->commuteId)->update([
        'start_date' => CarbonImmutable::today()->subMonth()->toDateString(),
    ]);

    $copyId = test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/duplicate")
        ->assertStatus(201)->json('data.id');

    $schedule = CommuteSchedule::query()->where('commute_offer_id', $copyId)->sole();

    expect(CarbonImmutable::parse($schedule->start_date)->toDateString())
        ->toBe(CarbonImmutable::tomorrow()->toDateString())
        // The end she chose is kept: it is how long she committed for.
        ->and(CarbonImmutable::parse($schedule->end_date)->toDateString())
        ->toBe(CarbonImmutable::today()->addMonths(3)->toDateString());
});

/**
 * 🔴 And a window with nothing left in it carries no schedule at all. Shifting the end as well
 * would be inventing how long she is committing for — hers to decide, and the number a passenger
 * plans a term around.
 */
it('carries no schedule from a window that is entirely spent', function () {
    CommuteSchedule::query()->where('commute_offer_id', $this->commuteId)->update([
        'start_date' => CarbonImmutable::today()->subMonths(4)->toDateString(),
        'end_date' => CarbonImmutable::today()->subMonth()->toDateString(),
    ]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/duplicate")
        ->assertStatus(201)
        // Null rather than a guessed window, so the client can send her to the schedule step.
        ->assertJsonPath('data.schedule', null);
});

/*
|--------------------------------------------------------------------------
| Return
|--------------------------------------------------------------------------
*/

/**
 * 🔴 Reversing is not only swapping the ends. On the way to work she PICKS UP near people's homes;
 * on the way back those same places are where she SETS THEM DOWN — and in the opposite order,
 * because the first home she collects from in the morning is the last she reaches in the evening.
 */
it('reverses the route, the order of the stops and each stop’s role', function () {
    $copyId = test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/return")
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'DRAFT')
        // Home, not to work.
        ->assertJsonPath('data.direction', 'to_home')
        ->json('data.id');

    $draft = CommuteOffer::query()->whereKey($copyId)->with('locations')->sole();

    $ordered = $draft->locations->sortBy('sequence')->values();

    expect($ordered->pluck('address')->all())->toBe([
        // It starts where the morning ended.
        'Smart Village B6',
        // Then the advertised stops, backwards.
        'Mehwar, Sheikh Zayed',
        'Ring Road, Rehab exit',
        'Rehab Gate 2',
    ]);

    expect($ordered->pluck('type')->all())->toBe([
        CommuteLocationType::Origin,
        // 🔴 Drop-offs now. A reversal that kept them as pickups would advertise a journey home
        // that collects people from their own front doors.
        CommuteLocationType::Dropoff,
        CommuteLocationType::Dropoff,
        CommuteLocationType::Destination,
    ]);
});

/**
 * 🔴 No schedule, and that is why this is a different shortcut from `duplicate`. A journey home
 * leaves at a different time of day; a prefilled 07:05 is a wrong value in the field she is least
 * likely to re-read, because it already looks filled in.
 */
it('prefills no times for the journey home', function () {
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/return")
        ->assertStatus(201)
        ->assertJsonPath('data.schedule', null);
});

it('carries the terms and preferences home with her', function () {
    $copyId = test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/return")
        ->assertStatus(201)
        ->assertJsonPath('data.seatsTotal', 3)
        ->assertJsonPath('data.audience', 'women_only')
        ->json('data.id');

    $draft = CommuteOffer::query()->whereKey($copyId)->with('rules')->sole();

    expect($draft->rules->pluck('rule_key')->map->value->sort()->values()->all())
        ->toBe(['nonsmoking', 'quiet']);
});

it('copies nobody either', function () {
    $live = commuteWithAPassenger($this->driverToken, $this->commuteId);

    $copyId = test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/return")
        ->assertStatus(201)->json('data.id');

    expect(SeatRequest::query()->where('commute_offer_id', $copyId)->count())->toBe(0)
        ->and(ScheduledTrip::query()->where('commute_offer_id', $copyId)->count())->toBe(0)
        ->and(Booking::query()->whereKey($live['bookingId'])->sole()->scheduled_trip_id)->toBe($live['tripId']);
});

/*
|--------------------------------------------------------------------------
| Both
|--------------------------------------------------------------------------
*/

/**
 * 🔒 Somebody else's commute is a 404, never a 403 — a 403 would confirm the id exists, and a
 * commute names where a person leaves from every morning.
 */
it('refuses to duplicate somebody else’s commute', function (string $shortcut) {
    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223339999', devicePublicId: 'driver-2', seed: 2);

    test()->flushHeaders()->withToken($otherDriver)
        ->postJson("/api/v1/commutes/{$this->commuteId}/{$shortcut}")
        ->assertStatus(404);

    expect(CommuteOffer::query()->count())->toBe(1);
})->with(['duplicate', 'return']);

/**
 * 🔴 Guarded like a creation, because that is what it is. The vehicle the original was sized for may
 * have been replaced since — and a draft tied to a car nobody has approved is a journey that cannot
 * legally start, discovered at the kerb.
 */
it('refuses the shortcut when the car is no longer usable', function (string $shortcut) {
    Vehicle::query()->whereKey($this->vehicleId)->update(['is_active' => false]);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/{$shortcut}")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_VEHICLE_UNAVAILABLE');

    expect(CommuteOffer::query()->count())->toBe(1);
})->with(['duplicate', 'return']);

/**
 * An archived commute can still be duplicated, and that is deliberate: reviving last term's pattern
 * is one of the main reasons to reach for this button. Nothing about the source's status changes
 * what is safe to copy.
 */
it('duplicates an archived commute', function () {
    // Only a published or paused commute can be archived, so it has to run first.
    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    test()->flushHeaders()->withToken($this->driverToken)
        ->deleteJson("/api/v1/commutes/{$this->commuteId}")->assertOk();

    expect(CommuteOffer::query()->whereKey($this->commuteId)->sole()->status)
        ->toBe(CommuteOfferStatus::Archived);

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/duplicate")
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'DRAFT');
});

/**
 * And the draft is a real draft: the ordinary wizard takes it from here.
 */
it('leaves a duplicate publishable', function () {
    $copyId = test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/duplicate")
        ->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$copyId}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'PUBLISHED');

    expect(ScheduledTrip::query()->where('commute_offer_id', $copyId)->count())->toBeGreaterThan(0);
});
