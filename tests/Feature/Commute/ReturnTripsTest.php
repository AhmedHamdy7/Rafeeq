<?php

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\ScheduleLock;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Trip\Actions\AutoFinishTripsAction;
use App\Domains\Trip\Models\TripSession;
use App\Http\Resources\TripSessionResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);

    $this->token = approvedDriver();
    $this->vehicleId = Vehicle::sole()->id;
});

function rtAs(string $token)
{
    return test()->flushHeaders()->withToken($token);
}

function publishedGoing(string $token, string $vehicleId, string $arrival = '08:15:00'): string
{
    $id = readyCommute($token, $vehicleId, schedule: ['promisedArrivalTime' => $arrival]);
    rtAs($token)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    return $id;
}

function returnDraft(string $token, string $goingId): string
{
    return rtAs($token)->postJson("/api/v1/commutes/{$goingId}/return")->assertStatus(201)->json('data.id');
}

function returnSchedule(string $token, string $id, string $leave = '17:00:00')
{
    return rtAs($token)->putJson("/api/v1/commutes/{$id}/schedule", [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'promisedDepartureTime' => $leave,
        'startDate' => CarbonImmutable::tomorrow()->toDateString(),
        'endDate' => CarbonImmutable::today()->addMonths(4)->toDateString(),
    ]);
}

function publishedPair(string $token, string $vehicleId): array
{
    $going = publishedGoing($token, $vehicleId);
    $return = returnDraft($token, $going);
    returnSchedule($token, $return)->assertOk();
    rtAs($token)->postJson("/api/v1/commutes/{$return}/publish")->assertOk();

    return [$going, $return];
}

it('B1: stores going / return and accepts the old values from old builds', function () {
    createCommute($this->token, $this->vehicleId, ['direction' => 'to_home'])->assertStatus(201)->assertJsonPath('data.direction', 'return');
    createCommute($this->token, $this->vehicleId, ['direction' => 'return'])->assertStatus(201)->assertJsonPath('data.direction', 'return');

    $old = test()->flushHeaders()->withToken($this->token)->postJson('/api/v1/commutes', ['vehicleId' => $this->vehicleId]);
    $old->assertStatus(201)->assertJsonPath('data.direction', 'going');

    rtAs($this->token)->getJson('/api/v1/commutes/options')
        ->assertOk()
        ->assertJsonMissingPath('data.defaults.direction')
        ->assertJsonPath('data.returnWindowMinutes', 30)
        ->assertJsonPath('data.commuteGapMinutes', 10);
});

it('B1: changes direction on a draft only, and going back to going drops the link', function () {
    $going = publishedGoing($this->token, $this->vehicleId);
    $return = returnDraft($this->token, $going);

    rtAs($this->token)->patchJson("/api/v1/commutes/{$return}", ['direction' => 'going'])
        ->assertOk()
        ->assertJsonPath('data.direction', 'going')
        ->assertJsonPath('data.returnOfCommuteId', null);

    rtAs($this->token)->patchJson("/api/v1/commutes/{$going}", ['direction' => 'return'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_NOT_EDITABLE');
});

it('B3: makes a linked Return draft with the same days and seats and no times', function () {
    $going = publishedGoing($this->token, $this->vehicleId);

    $draft = rtAs($this->token)->postJson("/api/v1/commutes/{$going}/return")
        ->assertStatus(201)
        ->assertJsonPath('data.direction', 'return')
        ->assertJsonPath('data.returnOfCommuteId', $going)
        ->assertJsonPath('data.goingCommute.id', $going)
        ->assertJsonPath('data.goingCommute.promisedArrivalLocal', '08:15')
        ->assertJsonPath('data.schedule.daysMask', DaysMask::weekdaysSunToThu()->value)
        ->json('data');

    expect(collect($draft['weekdays'])->pluck('promisedDepartureTime')->unique()->all())->toBe([null])
        ->and($draft['missing'])->toContain('promisedDepartureTime');

    rtAs($this->token)->postJson("/api/v1/commutes/{$going}/return")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_RETURN_EXISTS')
        ->assertJsonPath('error.fields.returnCommuteId', $draft['id']);

    rtAs($this->token)->postJson("/api/v1/commutes/{$draft['id']}/return")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_NOT_GOING');

    rtAs($this->token)->getJson("/api/v1/commutes/{$going}")
        ->assertOk()
        ->assertJsonPath('data.returnCommute.id', $draft['id'])
        ->assertJsonPath('data.returnCommute.status', 'DRAFT');
});

it('B2: times a Return by its leave time and refuses an arrival promise', function () {
    $going = publishedGoing($this->token, $this->vehicleId);
    $return = returnDraft($this->token, $going);

    rtAs($this->token)->putJson("/api/v1/commutes/{$return}/schedule", [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'promisedArrivalTime' => '18:00',
        'startDate' => CarbonImmutable::tomorrow()->toDateString(),
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    rtAs($this->token)->putJson("/api/v1/commutes/{$return}/schedule", [
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'startDate' => CarbonImmutable::tomorrow()->toDateString(),
    ])->assertStatus(422)->assertJsonPath('error.code', 'COMMUTE_INCOMPLETE')->assertJsonPath('error.fields.missing', ['promisedDepartureTime']);

    rtAs($this->token)->putJson("/api/v1/commutes/{$going}/schedule", ['promisedDepartureTime' => '07:00'])->assertStatus(409);

    $saved = returnSchedule($this->token, $return)->assertOk()->json('data.weekdays.0');

    expect($saved['promisedDepartureTime'])->toBe('17:00:00')
        ->and($saved['promisedArrivalTime'])->toBeNull()
        ->and($saved['departureIsComputed'])->toBeFalse()
        ->and($saved['estimatedArrivalTime'])->not->toBeNull();

    rtAs($this->token)->postJson("/api/v1/commutes/{$return}/publish")->assertOk();

    $day = ScheduledTrip::query()->where('commute_offer_id', $return)->orderBy('departure_at')->firstOrFail();

    expect($day->departure_local->format('H:i'))->toBe('17:00')
        ->and($day->promised_arrival_at)->toBeNull()
        ->and($day->promised_departure_at->equalTo($day->departure_at))->toBeTrue()
        ->and($day->estimated_arrival_at->greaterThan($day->departure_at))->toBeTrue();

    $trip = rtAs($this->token)->getJson("/api/v1/commutes/{$return}")->assertOk()->json('data.upcomingTrips.0');

    expect($trip['direction'])->toBe('return')
        ->and($trip['promisedArrivalLocal'])->toBeNull()
        ->and(substr((string) $trip['promisedDepartureLocal'], 11, 5))->toBe('17:00')
        ->and($trip['estimatedArrivalLocal'])->not->toBeNull();
});

it('B3: shows the pair on both sides and to passengers only once published', function () {
    $going = publishedGoing($this->token, $this->vehicleId);
    $return = returnDraft($this->token, $going);
    $pax = verifiedPassenger('01223339999', device: 'pax-2');

    rtAs($pax)->getJson("/api/v1/commutes/{$going}/public")->assertOk()->assertJsonPath('data.returnCommute', null);

    returnSchedule($this->token, $return)->assertOk();
    rtAs($this->token)->postJson("/api/v1/commutes/{$return}/publish")->assertOk();

    rtAs($pax)->getJson("/api/v1/commutes/{$going}/public")
        ->assertOk()
        ->assertJsonPath('data.returnCommute.id', $return)
        ->assertJsonPath('data.returnCommute.departureLocal', '17:00');

    rtAs($pax)->getJson("/api/v1/commutes/{$return}/public")
        ->assertOk()
        ->assertJsonPath('data.direction', 'return')
        ->assertJsonPath('data.goingCommute.id', $going)
        ->assertJsonPath('data.goingCommute.promisedArrivalLocal', '08:15');
});

it('S10: deleting either side keeps the other and clears the link', function () {
    [$going, $return] = publishedPair($this->token, $this->vehicleId);

    rtAs($this->token)->deleteJson("/api/v1/commutes/{$going}")->assertOk();

    expect(CommuteOffer::query()->whereKey($return)->value('return_of_commute_id'))->toBeNull()
        ->and(CommuteOffer::query()->whereKey($return)->value('status')->value)->toBe('published');
});

it('D3: lets the Return leave 10 minutes after the Going arrives, not earlier', function () {
    $going = publishedGoing($this->token, $this->vehicleId, '10:00:00');
    $return = returnDraft($this->token, $going);

    returnSchedule($this->token, $return, '10:05:00')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_SCHEDULE_OVERLAP')
        ->assertJsonPath('error.fields.commuteId', $going)
        ->assertJsonPath('error.fields.otherArrivesAtLocal', '10:00')
        ->assertJsonPath('error.fields.earliestStartLocal', '10:10');

    returnSchedule($this->token, $return, '10:10:00')->assertOk();
    rtAs($this->token)->postJson("/api/v1/commutes/{$return}/publish")->assertOk();
});

it('B4: a Return search matches by leave time in [T, T + window]', function () {
    [$going, $return] = publishedPair($this->token, $this->vehicleId);
    $pax = verifiedPassenger('01223339999', device: 'pax-2');

    $back = [
        'origin' => ['lat' => 30.0714, 'lng' => 30.9716],
        'destination' => ['lat' => 30.0594, 'lng' => 31.4913],
        'direction' => 'return',
    ];

    $ids = fn (array $overrides) => collect(rtAs($pax)->getJson('/api/v1/search/commutes?'.http_build_query(searchCriteria([...$back, ...$overrides])))->assertOk()->json('data'))->pluck('commuteId')->all();

    expect($ids(['leaveAt' => '16:45']))->toBe([$return])
        ->and($ids(['leaveAt' => '17:00']))->toBe([$return])
        ->and($ids(['leaveAt' => '17:10']))->toBe([]);

    $hit = rtAs($pax)->getJson('/api/v1/search/commutes?'.http_build_query(searchCriteria([...$back, 'leaveAt' => '16:45'])))->json('data.0');

    expect($hit['direction'])->toBe('return')
        ->and(substr((string) $hit['promisedDepartureLocal'], 11, 5))->toBe('17:00');

    $goingIds = collect(search($pax, ['arriveBy' => '08:30'])->assertOk()->json('data'))->pluck('commuteId')->all();

    expect($goingIds)->toBe([$going]);

    $first = collect(search($pax, ['arriveBy' => '08:30'])->json('data'))->first();

    expect($first['returnCommute']['id'])->toBe($return);
});

it('B6: saves the ride back with a Going demand in one call', function () {
    $pax = verifiedPassenger('01223339999', device: 'pax-2');

    $response = rtAs($pax)->postJson('/api/v1/commute-demands', [
        ...searchCriteria(['arriveBy' => '08:30']),
        'returnDemand' => ['leaveAt' => '17:00'],
    ])->assertStatus(201);

    $going = $response->json('data');
    $back = $response->json('data.returnDemand');

    expect($going['direction'])->toBe('going')
        ->and($back['direction'])->toBe('return')
        ->and($back['leaveAt'])->toBe('17:00')
        ->and($back['arrivalWindowStart'])->toBeNull()
        ->and($back['origin']['lat'])->toBe($going['destination']['lat'])
        ->and($back['pairedDemandId'])->toBe($going['id'])
        ->and($going['pairedDemandId'])->toBe($back['id']);

    rtAs($pax)->deleteJson("/api/v1/commute-demands/{$back['id']}")->assertOk();

    expect(CommuteDemand::query()->whereKey($going['id'])->value('paired_demand_id'))->toBeNull();
});

it('S6 and B9: suggests the Return after a seat request, and tells the driver who rides both ways', function () {
    [$going, $return] = publishedPair($this->token, $this->vehicleId);
    $pax = verifiedPassenger('01223339999', device: 'pax-2');

    $goingTrip = ScheduledTrip::query()->where('commute_offer_id', $going)->orderBy('departure_at')->firstOrFail();
    $returnTrip = ScheduledTrip::query()->where('commute_offer_id', $return)->where('trip_date', $goingTrip->trip_date)->firstOrFail();

    $request = requestSeat($pax, $going, ['scheduledTripId' => $goingTrip->id])->assertStatus(201);

    expect($request->json('data.commute.returnCommute.id'))->toBe($return)
        ->and($request->json('data.commute.returnCommute.departureLocal'))->toBe('17:00')
        ->and($request->json('data.commute.returnCommute.trialTripId'))->toBe($returnTrip->id)
        ->and($request->json('data.commute.returnCommute.myStatus'))->toBeNull()
        ->and($request->json('data.commute.direction'))->toBe('going');

    approveSeat($this->token, $request->json('data.id'));

    $back = requestSeat($pax, $return, [
        'scheduledTripId' => $returnTrip->id,
        'pickup' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
        'dropoff' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road, Rehab exit'],
    ])->assertStatus(201)->json('data.id');

    $row = collect(rtAs($this->token)->getJson('/api/v1/driver/seat-requests')->assertOk()->json('data'))->firstWhere('id', $back);

    expect($row['alsoOnLinked'])->toBe('booked')
        ->and($row['direction'])->toBe('return');

    $again = rtAs($pax)->getJson("/api/v1/seat-requests/{$request->json('data.id')}")->assertOk();

    expect($again->json('data.commute.returnCommute.myStatus'))->toBe('pending');
});

it('round 2, items 1, 3 and 7: price and free seats on the suggestion, her seat on the linked commute for the driver', function () {
    [$going, $return] = publishedPair($this->token, $this->vehicleId);
    $pax = verifiedPassenger('01223338888', device: 'pax-3');

    $goingTrip = ScheduledTrip::query()->where('commute_offer_id', $going)->orderBy('departure_at')->firstOrFail();
    $returnTrip = ScheduledTrip::query()->where('commute_offer_id', $return)->where('trip_date', $goingTrip->trip_date)->firstOrFail();
    ScheduledTrip::query()->whereKey($returnTrip->id)->update(['seats_taken' => 1]);

    $request = requestSeat($pax, $going, ['scheduledTripId' => $goingTrip->id])->assertStatus(201);

    expect($request->json('data.commute.returnCommute.seatPricePiastres'))->toBe((int) CommuteOffer::query()->findOrFail($return)->seat_price_piastres)
        ->and($request->json('data.commute.returnCommute.seatsAvailable'))->toBe($returnTrip->seats_total - 1);

    $goingBooking = approveSeat($this->token, $request->json('data.id'));

    $back = requestSeat($pax, $return, [
        'scheduledTripId' => $returnTrip->id,
        'pickup' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
        'dropoff' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road, Rehab exit'],
    ])->assertStatus(201)->json('data.id');

    rtAs($this->token)->getJson("/api/v1/driver/seat-requests/{$back}/route-preview")
        ->assertOk()->assertJsonPath('data.alsoOnLinked', 'booked');

    $returnBooking = approveSeat($this->token, $back);

    $row = collect(rtAs($this->token)->getJson('/api/v1/driver/bookings')->assertOk()->json('data'))->firstWhere('id', $goingBooking);

    expect($row['alsoOnLinked'])->toBe('booked')
        ->and($row['linkedBooking']['bookingId'])->toBe($returnBooking)
        ->and($row['linkedBooking']['commuteId'])->toBe($return)
        ->and($row['linkedBooking']['direction'])->toBe('return')
        ->and($row['linkedBooking']['pickupLabel'])->toBe('Mehwar, Sheikh Zayed')
        ->and($row['linkedBooking']['daysMask'])->toBeNull()
        ->and($row['linkedBooking']['tripDate'])->toBe($goingTrip->trip_date->toDateString());

    expect(rtAs($pax)->getJson("/api/v1/bookings/{$goingBooking}")->assertOk()->json('data'))->not->toHaveKey('linkedBooking');
});

it('round 2, item 2: every driver day row names the linked commute and its day on that date', function () {
    [$going, $return] = publishedPair($this->token, $this->vehicleId);

    $goingTrip = ScheduledTrip::query()->where('commute_offer_id', $going)->orderBy('departure_at')->firstOrFail();
    $returnTrip = ScheduledTrip::query()->where('commute_offer_id', $return)->where('trip_date', $goingTrip->trip_date)->firstOrFail();

    $row = collect(rtAs($this->token)->getJson('/api/v1/driver/trips?scope=upcoming&perPage=50')->assertOk()->json('data'))->firstWhere('id', $goingTrip->id);

    expect($row['linkedCommute']['id'])->toBe($return)
        ->and($row['linkedCommute']['direction'])->toBe('return')
        ->and($row['linkedCommute']['status'])->toBe('PUBLISHED')
        ->and($row['linkedCommute']['tripId'])->toBe($returnTrip->id)
        ->and(substr($row['linkedCommute']['departureLocal'], 11, 5))->toBe('17:00')
        ->and($row['linkedCommute']['promisedArrivalLocal'])->toBeNull()
        ->and($row['linkedCommute']['estimatedArrivalLocal'])->not->toBeNull();

    $back = collect(rtAs($this->token)->getJson('/api/v1/driver/trips?scope=upcoming&perPage=50')->json('data'))->firstWhere('id', $returnTrip->id);

    expect($back['linkedCommute']['id'])->toBe($going)
        ->and($back['linkedCommute']['direction'])->toBe('going')
        ->and($back['linkedCommute']['tripId'])->toBe($goingTrip->id)
        ->and(substr($back['linkedCommute']['promisedArrivalLocal'], 11, 5))->toBe('08:15');

    $upcoming = rtAs($this->token)->getJson("/api/v1/commutes/{$going}")->assertOk()->json('data.upcomingTrips');
    expect(collect($upcoming)->firstWhere('id', $goingTrip->id)['linkedCommute']['tripId'])->toBe($returnTrip->id);

    $home = rtAs($this->token)->getJson('/api/v1/driver/home')->assertOk();
    expect($home->json('data.nextRun.linkedCommute.id'))->not->toBeNull();
    foreach ($home->json('data.laterThisWeek') as $later) {
        expect($later)->toHaveKey('linkedCommute');
    }

    ScheduledTrip::query()->whereKey($returnTrip->id)->update(['status' => 'cancelled']);
    $alone = collect(rtAs($this->token)->getJson('/api/v1/driver/trips?scope=upcoming&perPage=50')->json('data'))->firstWhere('id', $goingTrip->id);

    expect($alone['linkedCommute']['id'])->toBe($return)
        ->and($alone['linkedCommute']['tripId'])->toBeNull()
        ->and($alone['linkedCommute']['departureLocal'])->toBeNull();

    rtAs($this->token)->deleteJson("/api/v1/commutes/{$return}")->assertOk();
    $single = collect(rtAs($this->token)->getJson('/api/v1/driver/trips?scope=upcoming&perPage=50')->json('data'))->firstWhere('id', $goingTrip->id);

    expect($single['linkedCommute'])->toBeNull();
});

it('round 2, item 4: a waiting-list application carries the Return suggestion too', function () {
    [$going, $return] = publishedPair($this->token, $this->vehicleId);
    $pax = verifiedPassenger('01223337777', device: 'pax-4');

    $goingTrip = ScheduledTrip::query()->where('commute_offer_id', $going)->orderBy('departure_at')->firstOrFail();
    ScheduledTrip::query()->whereKey($goingTrip->id)->update(['seats_taken' => $goingTrip->seats_total]);

    $request = requestSeat($pax, $going, ['scheduledTripId' => $goingTrip->id])->assertStatus(201);

    expect($request->json('data.kind'))->toBe('waitlist_application')
        ->and($request->json('data.commute.returnCommute.id'))->toBe($return);
});

it('round 2, item 5: Home gives a Return seat her drop-off estimate', function () {
    [, $return] = publishedPair($this->token, $this->vehicleId);
    $pax = verifiedPassenger('01223336666', device: 'pax-5');

    $returnTrip = ScheduledTrip::query()->where('commute_offer_id', $return)->orderBy('departure_at')->firstOrFail();

    approveSeat($this->token, requestSeat($pax, $return, [
        'scheduledTripId' => $returnTrip->id,
        'pickup' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
        'dropoff' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road, Rehab exit'],
    ])->assertStatus(201)->json('data.id'));

    $journey = rtAs($pax)->getJson('/api/v1/home')->assertOk()->json('data.nextJourney');

    expect($journey['direction'])->toBe('return')
        ->and($journey['dropoff']['promisedArrivalLocal'])->toBeNull()
        ->and($journey['dropoff']['estimatedArrivalLocal'])->not->toBeNull()
        ->and($journey['dropoff']['estimatedArrivalLocal'] <= $journey['estimatedArrivalLocal'])->toBeTrue();
});

it('round 2, item 6: a change proposal on a Return carries the leave time and the direction', function () {
    [, $return] = publishedPair($this->token, $this->vehicleId);
    $pax = verifiedPassenger('01223335555', device: 'pax-6');

    $returnTrip = ScheduledTrip::query()->where('commute_offer_id', $return)->orderBy('departure_at')->firstOrFail();

    $booking = approveSeat($this->token, requestSeat($pax, $return, [
        'scheduledTripId' => $returnTrip->id,
        'pickup' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
        'dropoff' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road, Rehab exit'],
    ])->assertStatus(201)->json('data.id'));

    $proposal = rtAs($this->token)->postJson("/api/v1/driver/commutes/{$return}/change-proposals", [
        'scheduledTripId' => $returnTrip->id,
        'scope' => 'only_this_occurrence',
        'promisedDepartureTime' => '17:15',
    ])->assertStatus(201);

    expect($proposal->json('data.direction'))->toBe('return')
        ->and($proposal->json('data.changes.promisedDepartureTime'))->toBe('17:15:00')
        ->and($proposal->json('data.previous.promisedDepartureTime'))->toBe('17:00:00');

    $hers = rtAs($pax)->getJson("/api/v1/change-proposals?bookingId={$booking}")->assertOk()->json('data.0');

    expect($hers['direction'])->toBe('return')
        ->and($hers['changes']['promisedDepartureTime'])->toBe('17:15:00');
});

function returnRider(string $driverToken, string $returnId, string $phone, string $device): array
{
    $pax = verifiedPassenger($phone, device: $device);
    $trip = ScheduledTrip::query()->where('commute_offer_id', $returnId)->orderBy('departure_at')->firstOrFail();

    $booking = approveSeat($driverToken, requestSeat($pax, $returnId, [
        'scheduledTripId' => $trip->id,
        'pickup' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
        'dropoff' => ['lat' => 30.0624, 'lng' => 31.3614, 'label' => 'Ring Road, Rehab exit'],
    ])->assertStatus(201)->json('data.id'));

    return ['token' => $pax, 'trip' => $trip, 'bookingId' => $booking];
}

function minutesApart(string $from, string $to): int
{
    return (int) round((strtotime("2000-01-01 {$to}") - strtotime("2000-01-01 {$from}")) / 60);
}

it('round 3, items 1 and 2: a Return proposal carries the arrival estimate and her own pickup times', function () {
    [, $return] = publishedPair($this->token, $this->vehicleId);
    $rider = returnRider($this->token, $return, '01223334444', 'pax-7');

    $driver = rtAs($this->token)->postJson("/api/v1/driver/commutes/{$return}/change-proposals", [
        'scheduledTripId' => $rider['trip']->id,
        'scope' => 'only_this_occurrence',
        'promisedDepartureTime' => '17:15',
    ])->assertStatus(201);

    $before = $driver->json('data.previous.estimatedArrivalTime');
    $after = $driver->json('data.changes.estimatedArrivalTime');

    expect($before)->not->toBeNull()
        ->and(minutesApart($before, $after))->toBe(15);

    $listed = collect(rtAs($this->token)->getJson('/api/v1/driver/change-proposals')->assertOk()->json('data'))->firstWhere('id', $driver->json('data.id'));
    expect($listed['changes']['estimatedArrivalTime'])->toBe($after);

    $hers = rtAs($rider['token'])->getJson("/api/v1/change-proposals?bookingId={$rider['bookingId']}")->assertOk()->json('data.0');

    expect(minutesApart($hers['previous']['estimatedArrivalTime'], $hers['changes']['estimatedArrivalTime']))->toBe(15)
        ->and($hers['previous']['estimatedArrivalTime'] <= $before)->toBeTrue()
        ->and($hers['myPickup']['label'])->toBe('Mehwar, Sheikh Zayed')
        ->and(minutesApart($hers['myPickup']['previousPickupLocal'], $hers['myPickup']['newPickupLocal']))->toBe(15);

    $roster = $listed['passengers'][0];
    expect($roster['previousPickupLocal'])->toBe($hers['myPickup']['previousPickupLocal'])
        ->and($roster['newPickupLocal'])->toBe($hers['myPickup']['newPickupLocal']);

    rtAs($this->token)->getJson("/api/v1/change-proposals/{$driver->json('data.id')}")->assertStatus(404);
    $one = rtAs($rider['token'])->getJson("/api/v1/change-proposals/{$driver->json('data.id')}?bookingId={$rider['bookingId']}")->assertOk();
    expect($one->json('data.myPickup.newPickupLocal'))->toBe($hers['myPickup']['newPickupLocal']);
});

it('round 3, item 1: a Going proposal has no arrival estimate', function () {
    $going = publishedGoing($this->token, $this->vehicleId);
    $pax = verifiedPassenger('01223333333', device: 'pax-8');
    $trip = ScheduledTrip::query()->where('commute_offer_id', $going)->orderBy('departure_at')->firstOrFail();
    $booking = approveSeat($this->token, requestSeat($pax, $going, ['scheduledTripId' => $trip->id])->assertStatus(201)->json('data.id'));

    $proposal = rtAs($this->token)->postJson("/api/v1/driver/commutes/{$going}/change-proposals", [
        'scheduledTripId' => $trip->id,
        'scope' => 'only_this_occurrence',
        'promisedArrivalTime' => '08:30',
    ])->assertStatus(201);

    expect($proposal->json('data.changes.estimatedArrivalTime'))->toBeNull()
        ->and($proposal->json('data.previous.estimatedArrivalTime'))->toBeNull();

    $hers = rtAs($pax)->getJson("/api/v1/change-proposals?bookingId={$booking}")->assertOk()->json('data.0');
    expect($hers['changes']['estimatedArrivalTime'])->toBeNull()
        ->and($hers)->toHaveKey('myPickup');
});

it('round 3, item 3: the driver\'s days say whether their time is already locked', function () {
    $going = publishedGoing($this->token, $this->vehicleId);
    $trips = ScheduledTrip::query()->where('commute_offer_id', $going)->orderBy('departure_at')->get();
    $soon = $trips->first();
    ScheduledTrip::query()->whereKey($soon->id)->update(['departure_at' => now()->addHours(2)]);

    $days = collect(rtAs($this->token)->getJson("/api/v1/commutes/{$going}")->assertOk()->json('data.upcomingTrips'));

    expect($days->firstWhere('id', $soon->id)['timeLocked'])->toBeTrue()
        ->and($days->firstWhere('id', $trips->last()->id)['timeLocked'])->toBeFalse()
        ->and($days->firstWhere('id', $trips->last()->id)['timeLockedAt'])->toBe(
            $trips->last()->departure_at->copy()->subHours(ScheduleLock::hours())->toIso8601String(),
        );

    $pax = verifiedPassenger('01223332222', device: 'pax-9');
    $public = collect(rtAs($pax)->getJson("/api/v1/commutes/{$going}/public")->assertOk()->json('data.upcomingTrips') ?? []);
    expect($public->every(fn (array $day): bool => ! array_key_exists('timeLocked', $day)))->toBeTrue();
});

it('round 3, item 4: a Return run counts its still-driving check from the estimate it set off with', function () {
    [, $return] = publishedPair($this->token, $this->vehicleId);
    $trip = ScheduledTrip::query()->where('commute_offer_id', $return)->orderBy('departure_at')->firstOrFail();

    expect($trip->promised_arrival_at)->toBeNull()
        ->and($trip->estimated_arrival_at)->not->toBeNull()
        ->and(AutoFinishTripsAction::countsFrom($trip)->equalTo($trip->estimated_arrival_at))->toBeTrue();

    $session = TripSession::factory()->create(['scheduled_trip_id' => $trip->id, 'departed_at' => now()]);
    $shape = (new TripSessionResource($session))->toArray(request());

    expect($shape['expectedArrivalAt'])->toBe($trip->estimated_arrival_at->toIso8601String());
});
