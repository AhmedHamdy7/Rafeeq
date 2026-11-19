<?php

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\DaysMask;

beforeEach(function () {
    config(['rafeeq.testing.api' => true]);
});

function patrolTrip(string $driverToken, array $overrides = []): array
{
    $commuteId = readyCommute($driverToken, Vehicle::query()->where('is_active', true)->sole()->id, $overrides);
    test()->flushHeaders()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    return [$commuteId, ScheduledTrip::query()->where('commute_offer_id', $commuteId)->orderBy('trip_date')->first()->id];
}

it('says when a seat request ended without an answer, and the age call moves it', function () {
    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId, $tripId] = patrolTrip($driver);
    $passenger = verifiedPassenger('01020001001');
    $requestId = requestSeat($passenger, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');

    $pending = test()->flushHeaders()->withToken($passenger)->getJson('/api/v1/seat-requests')->assertOk()->json('data.0');
    expect($pending['endedAt'])->toBeNull()->and($pending['updatedAt'])->not->toBeNull();

    test()->flushHeaders()->withToken($driver)->deleteJson("/api/v1/commutes/{$commuteId}")->assertOk();

    $ended = collect(test()->flushHeaders()->withToken($passenger)->getJson('/api/v1/seat-requests')->assertOk()->json('data'))
        ->firstWhere('id', $requestId);

    expect($ended['status'])->toBe('COMMUTE_ARCHIVED')
        ->and($ended['respondedAt'])->toBeNull()
        ->and($ended['endedAt'])->not->toBeNull();

    $aged = test()->flushHeaders()->withToken($passenger)->postJson("/api/v1/testing/seat-requests/{$requestId}/age", ['days' => 8])
        ->assertOk()->json('data');

    expect(now()->diffInDays($aged['endedAt'], true))->toBeGreaterThanOrEqual(7.9);

    $after = collect(test()->flushHeaders()->withToken($passenger)->getJson('/api/v1/seat-requests')->json('data'))->firstWhere('id', $requestId);
    expect(now()->diffInDays($after['endedAt'], true))->toBeGreaterThanOrEqual(7.9);
});

function patrolWaiting(string $driverToken, string $commuteId, string $phone, string $device): array
{
    $token = verifiedPassenger($phone, device: $device);
    $requestId = requestSeat($token, $commuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($driverToken)->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk();

    return ['token' => $token, 'requestId' => $requestId];
}

it('keeps a grouped offer open for the mornings nobody took', function () {
    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId, $tripId] = patrolTrip($driver, ['seatsTotal' => 1]);
    $first = patrolWaiting($driver, $commuteId, '01020001001', 'pax-1');
    $second = patrolWaiting($driver, $commuteId, '01020001002', 'pax-2');

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/driver/commutes/{$commuteId}/waitlist/offers", ['all' => true])->assertOk();

    $firstOffer = SeatOffer::query()->where('seat_request_id', $first['requestId'])->where('scheduled_trip_id', $tripId)->sole();
    $days = SeatOffer::query()->where('seat_request_id', $second['requestId'])->count();

    expect($days)->toBeGreaterThan(1)
        ->and(SeatOffer::query()->where('seat_request_id', $first['requestId'])->count())->toBe($days);

    test()->flushHeaders()->withToken($first['token'])->postJson("/api/v1/seat-offers/{$firstOffer->id}/accept")->assertOk();

    $group = SeatOffer::query()->where('seat_request_id', $second['requestId'])->value('release_event_id');

    $accepted = test()->flushHeaders()->withToken($second['token'])->postJson("/api/v1/seat-offers/groups/{$group}/accept")->assertOk();

    $statuses = collect($accepted->json('meta.offers'))->countBy('status')->all();

    expect($statuses)->toBe(['TAKEN_BY_OTHER' => 1, 'ACCEPTED' => $days - 1])
        ->and(Booking::query()->where('passenger_user_id', User::query()->where('phone_e164', '+201020001002')->value('id'))->count())->toBe($days - 1);
});

it('tells a passenger who lost the race exactly that', function () {
    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId, $tripId] = patrolTrip($driver, ['seatsTotal' => 1]);
    $first = patrolWaiting($driver, $commuteId, '01020001001', 'pax-1');
    $second = patrolWaiting($driver, $commuteId, '01020001002', 'pax-2');

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/driver/commutes/{$commuteId}/waitlist/offers", ['all' => true])->assertOk();

    $winner = SeatOffer::query()->where('seat_request_id', $first['requestId'])->where('scheduled_trip_id', $tripId)->sole();
    $loser = SeatOffer::query()->where('seat_request_id', $second['requestId'])->where('scheduled_trip_id', $tripId)->sole();

    test()->flushHeaders()->withToken($first['token'])->postJson("/api/v1/seat-offers/{$winner->id}/accept")->assertOk();

    test()->flushHeaders()->withToken($second['token'])->postJson("/api/v1/seat-offers/{$loser->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SEAT_OFFER_CLOSED')
        ->assertJsonPath('error.fields.status.0', 'taken_by_other');
});

it('names YuGo when she is under age', function () {
    $token = signIn(phone: '01020001003', devicePublicId: 'pax-3')['session']['accessToken'];

    test()->flushHeaders()->withToken($token)->putJson('/api/v1/account/profile/basic', [
        'fullName' => 'سارة محمود',
        'gender' => 'woman',
        'registeredRole' => 'passenger',
        'dateOfBirth' => now()->subYears(17)->toDateString(),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.fields.dateOfBirth.0', 'You must be at least 18 years old to use YuGo.');
});

it('lists her upcoming days after a recurring approval', function () {
    $driver = approvedDriver('01020001009', 'driver');
    [$commuteId] = patrolTrip($driver);
    $passenger = verifiedPassenger('01020001003');
    $requestId = requestSeat($passenger, $commuteId, ['commitment' => 'recurring', 'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value])
        ->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertSuccessful();

    $upcoming = test()->flushHeaders()->withToken($passenger)->getJson('/api/v1/my-bookings?scope=upcoming')->assertOk()->json('data');

    expect(count($upcoming))->toBeGreaterThan(1)
        ->and($upcoming[0])->toHaveKey('driverDelay');

    test()->flushHeaders()->withToken($passenger)->getJson('/api/v1/my-bookings?scope=upcoming&page=1&perPage=20')->assertOk();
});

it('lists her days right after a recurring approval on a one-weekday commute three days out (g117)', function () {
    $driver = approvedDriver('01020001009', 'driver');
    $day = now()->addDays(3);
    $mask = DaysMask::fromBits(1 << $day->dayOfWeek)->value;
    $commuteId = createCommute($driver, Vehicle::query()->where('is_active', true)->sole()->id)->assertStatus(201)->json('data.id');
    saveRoute($driver, $commuteId)->assertOk();
    saveSchedule($driver, $commuteId, ['daysMask' => $mask, 'startDate' => $day->toDateString()])->assertOk();
    test()->flushHeaders()->withToken($driver)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $passenger = verifiedPassenger('01020001003');
    $requestId = requestSeat($passenger, $commuteId, ['commitment' => 'recurring', 'requestedDaysMask' => $mask])
        ->assertStatus(201)->json('data.id');

    $approved = test()->flushHeaders()->withToken($driver)->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertSuccessful();

    $upcoming = test()->flushHeaders()->withToken($passenger)->getJson('/api/v1/my-bookings?scope=upcoming')->assertOk()->json('data');

    expect($upcoming)->not->toBeEmpty()
        ->and(collect($upcoming)->pluck('status')->unique()->all())->toBe(['CONFIRMED'])
        ->and($upcoming[0]['commuteGroupId'])->not->toBeNull()
        ->and(count($upcoming))->toBe(count($approved->json('data.bookings') ?? $approved->json('data.bookingIds') ?? $upcoming));
});
