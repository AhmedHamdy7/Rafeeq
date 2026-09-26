<?php

use App\Domains\Driver\Models\Vehicle;
use App\Domains\Matching\Actions\SaveCommuteDemandAction;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Jobs\NotifyMatchingDemands;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\MatchNotification;
use App\Domains\Matching\Support\SearchCriteria;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Shared\ValueObjects\WalkTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * The private demand (Chapter 5's "Save this commute request?") and the matching
 * that happens later.
 *
 * 🔒 The row a demand creates is SECRET. Chapter 5 says it twice — "passenger
 * demand is private", "drivers never browse passenger demands" — and it is the
 * line between Rafeeq and an auction on people. A driver who could browse demands
 * would be shopping for passengers; instead a driver publishes their own journey
 * and the platform tells the passenger.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->paxToken = passenger('01112223344');
});

function saveDemand(string $token, array $overrides = [])
{
    return test()->withToken($token)->postJson('/api/v1/commute-demands', array_merge([
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716],
        'daysMask' => DaysMask::weekdaysSunToThu()->value,
        'arrivalWindowStart' => '06:45:00',
        'arrivalWindowEnd' => '08:00:00',
        'maxWalkMinutes' => 15,
        'maxDetourMinutes' => 15,
    ], $overrides));
}

it('saves what was searched for, and sets it to expire', function () {
    $demand = saveDemand($this->paxToken)->assertStatus(201)->json('data');

    expect($demand['status'])->toBe('ACTIVE')
        ->and($demand['expiresAt'])->not->toBeNull()
        // A request nobody matched for two months is no longer what that person
        // wants, and notifying them then would be worse than silence.
        ->and(CommuteDemand::sole()->expires_at->isFuture())->toBeTrue();
});

it('cannot be told it is already matched by the request that creates it', function () {
    saveDemand($this->paxToken, ['status' => 'matched'])->assertStatus(201);

    expect(CommuteDemand::sole()->status)->toBe(CommuteDemandStatus::Active);
});

it('lists only the caller own demands', function () {
    saveDemand($this->paxToken)->assertStatus(201);

    $otherPax = passenger('01223334455', device: 'pax-2');
    saveDemand($otherPax)->assertStatus(201);

    $mine = test()->withToken($this->paxToken)
        ->getJson('/api/v1/commute-demands')->assertOk()->json('data');

    expect($mine)->toHaveCount(1)
        ->and(CommuteDemand::count())->toBe(2);
});

/**
 * 🔒 The central privacy guarantee of the chapter.
 */
it('gives a driver no way to reach another person demand', function () {
    $demandId = saveDemand($this->paxToken)->assertStatus(201)->json('data.id');

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');

    // Not in their own list.
    expect(test()->withToken($driverToken)->getJson('/api/v1/commute-demands')
        ->assertOk()->json('data'))->toBe([]);

    // And not reachable by id either — cancelling is the only route that takes
    // one, and it is scoped to the owner.
    test()->withToken($driverToken)->deleteJson("/api/v1/commute-demands/{$demandId}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect(CommuteDemand::sole()->status)->toBe(CommuteDemandStatus::Active);
});

it('cancels a demand rather than deleting it', function () {
    $demandId = saveDemand($this->paxToken)->assertStatus(201)->json('data.id');

    test()->withToken($this->paxToken)->deleteJson("/api/v1/commute-demands/{$demandId}")->assertOk();

    // The row is why a notification was sent; erasing it would leave
    // notifications pointing at nothing.
    expect(CommuteDemand::sole()->status)->toBe(CommuteDemandStatus::Cancelled);
});

it('expires demands that have run out of time', function () {
    saveDemand($this->paxToken)->assertStatus(201);

    $this->travel((int) config('rafeeq.matching.demand_expiry_days') + 1)->days();

    expect(SaveCommuteDemandAction::expireOverdue())->toBe(1)
        ->and(CommuteDemand::sole()->status)->toBe(CommuteDemandStatus::Expired);
});

/**
 * Publishing is the driver's action; telling passengers is a consequence of it,
 * and must not make their publish slower or able to fail.
 */
it('queues the matching work when a commute is published, off the request', function () {
    Queue::fake();

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);

    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    Queue::assertPushed(NotifyMatchingDemands::class, fn ($job) => $job->commuteOfferId === $commuteId);
});

it('notifies a waiting passenger when a matching commute appears', function () {
    saveDemand($this->paxToken)->assertStatus(201);

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);

    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $notification = MatchNotification::sole();

    expect($notification->commute_offer_id)->toBe($commuteId)
        // The score AT THE TIME, recording why the interruption was justified.
        ->and($notification->score)->toBeGreaterThanOrEqual(
            (int) config('rafeeq.matching.notification_score_threshold')
        );
});

it('shows a passenger the matches found for their saved requests', function () {
    saveDemand($this->paxToken)->assertStatus(201);

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $matches = test()->withToken($this->paxToken)->getJson('/api/v1/matches')->assertOk()->json('data');

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['commuteId'])->toBe($commuteId)
        // Same restraint as a search result: a public first name and nothing more.
        ->and($matches[0]['driver'])->toHaveKey('publicFirstName')
        ->and($matches[0]['driver'])->not->toHaveKey('fullName');
});

it('shows a passenger nothing from anyone else demands', function () {
    saveDemand($this->paxToken)->assertStatus(201);

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $otherPax = passenger('01223334455', device: 'pax-2');

    expect(test()->withToken($otherPax)->getJson('/api/v1/matches')->assertOk()->json('data'))->toBe([]);
});

it('never notifies a man about a women-only commute', function () {
    $manToken = passenger('01223334455', gender: 'man', device: 'pax-man');
    saveDemand($manToken)->assertStatus(201);

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);

    // The reference commute is women-only.
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    // The notifier runs the demand back through the real search engine, so the
    // hard filter applies here exactly as it does to a search.
    expect(MatchNotification::count())->toBe(0);
});

it('never notifies about the same commute twice', function () {
    saveDemand($this->paxToken)->assertStatus(201);

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    // Pausing and resuming publishes again; the passenger must not be told twice.
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/pause")->assertOk();
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/resume")->assertOk();

    expect(MatchNotification::count())->toBe(1);
});

it('is the database that forbids a duplicate notification, not just the code', function () {
    saveDemand($this->paxToken)->assertStatus(201);

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    expect(fn () => MatchNotification::create([
        'commute_demand_id' => CommuteDemand::sole()->id,
        'commute_offer_id' => $commuteId,
        'score' => 90,
    ]))->toThrow(QueryException::class);
});

it('does not notify a cancelled or expired demand', function (string $status) {
    saveDemand($this->paxToken)->assertStatus(201);

    CommuteDemand::sole()->forceFill(['status' => $status])->save();

    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    expect(MatchNotification::count())->toBe(0);
})->with(['cancelled', 'expired']);

/**
 * Chapter 5 lists "duplicate saved searches" as an edge case.
 */
it('collapses a saved search that is saved twice', function () {
    $filters = [
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716],
        'daysMask' => 62,
        'arrivalWindowStart' => '06:45:00',
        'arrivalWindowEnd' => '08:00:00',
        'maxWalkMinutes' => 15,
        'maxDetourMinutes' => 15,
    ];

    $first = test()->withToken($this->paxToken)->postJson('/api/v1/saved-searches', [
        'title' => 'Rehab to Smart Village',
        'filters' => $filters,
    ])->assertStatus(201)->json('data.id');

    // The same filters under a different name are the same search: the person
    // tapping save again meant "keep this", not "make a second one".
    $second = test()->withToken($this->paxToken)->postJson('/api/v1/saved-searches', [
        'title' => 'Morning run',
        'filters' => $filters,
    ])->assertStatus(201)->json('data.id');

    expect($second)->toBe($first)
        ->and(test()->withToken($this->paxToken)->getJson('/api/v1/saved-searches')
            ->assertOk()->json('data'))->toHaveCount(1);
});

it('never exposes the internal deduplication key of a saved search', function () {
    $body = test()->withToken($this->paxToken)->postJson('/api/v1/saved-searches', [
        'filters' => [
            'origin' => ['lat' => 30.0594, 'lng' => 31.4913],
            'destination' => ['lat' => 30.0714, 'lng' => 30.9716],
            'daysMask' => 62,
            'arrivalWindowStart' => '06:45:00',
            'arrivalWindowEnd' => '08:00:00',
            'maxWalkMinutes' => 15,
            'maxDetourMinutes' => 15,
        ],
    ])->assertStatus(201)->getContent();

    expect($body)->not->toContain('signature');
});

it('lets a passenger delete only their own saved search', function () {
    $filters = [
        'origin' => ['lat' => 30.0594, 'lng' => 31.4913],
        'destination' => ['lat' => 30.0714, 'lng' => 30.9716],
        'daysMask' => 62,
        'arrivalWindowStart' => '06:45:00',
        'arrivalWindowEnd' => '08:00:00',
        'maxWalkMinutes' => 15,
        'maxDetourMinutes' => 15,
    ];

    $mine = test()->withToken($this->paxToken)
        ->postJson('/api/v1/saved-searches', ['filters' => $filters])
        ->assertStatus(201)->json('data.id');

    $otherPax = passenger('01223334455', device: 'pax-2');

    test()->withToken($otherPax)->deleteJson("/api/v1/saved-searches/{$mine}")->assertStatus(404);

    test()->withToken($this->paxToken)->deleteJson("/api/v1/saved-searches/{$mine}")->assertOk();
});

it('requires authentication for every matching route', function (string $method, string $uri) {
    test()->withoutToken()->json($method, $uri)->assertStatus(401);
})->with([
    ['GET', '/api/v1/search/commutes'],
    ['GET', '/api/v1/commute-demands'],
    ['POST', '/api/v1/commute-demands'],
    ['GET', '/api/v1/matches'],
    ['GET', '/api/v1/saved-searches'],
    ['POST', '/api/v1/saved-searches'],
]);

/**
 * 🔴 The budget is a MONTHLY ceiling, and treating it as a per-ride one silently disabled
 * a whole scoring component.
 *
 * Three sources say monthly — the column, the ERD, and screen 18's stepper, which runs from
 * 800 to 3000 EGP in steps of 100 where a per-ride price is 70 to 95. A passenger who
 * filled the screen in as designed sent ~1600, it was compared against one trip's price,
 * every commute came in "under budget", and the five points for price were full marks for
 * everybody.
 */
it('records the budget as a monthly ceiling', function () {
    $demand = saveDemand($this->paxToken, [
        'budgetMonthlyPiastres' => 160000,
        'flexibilityMinutes' => 20,
        'wantsReturnTrip' => true,
    ])->assertStatus(201)->json('data');

    expect($demand['budgetMonthlyPiastres'])->toBe(160000)
        ->and($demand['flexibilityMinutes'])->toBe(20)
        ->and($demand['wantsReturnTrip'])->toBeTrue();

    $stored = CommuteDemand::sole();

    expect($stored->budget_monthly_piastres)->toBe(160000)
        ->and($stored->flexibility_minutes)->toBe(20)
        ->and($stored->wants_return_trip)->toBeTrue();
});

it('derives the per-ride ceiling from the month and the days', function () {
    // 1,600 EGP a month over five days a week, one way.
    $criteria = new SearchCriteria(
        origin: new Coordinate(30.0594, 31.4913),
        destination: new Coordinate(30.0714, 30.9716),
        days: DaysMask::weekdaysSunToThu(),
        arrivalWindowStart: '08:00',
        arrivalWindowEnd: '08:30',
        maxWalk: WalkTime::fromMinutes(15),
        maxDetourMinutes: 10,
        budgetMonthlyPiastres: 160000,
    );

    // Five days × 52/12 weeks ≈ 21.7 rides, so about 73 EGP a ride.
    expect($criteria->perSeatCeilingPiastres())->toBeGreaterThan(7000)
        ->and($criteria->perSeatCeilingPiastres())->toBeLessThan(7500);
});

it('halves the per-ride ceiling when a return leg is wanted', function () {
    $oneWay = fn (bool $return) => (new SearchCriteria(
        origin: new Coordinate(30.0594, 31.4913),
        destination: new Coordinate(30.0714, 30.9716),
        days: DaysMask::weekdaysSunToThu(),
        arrivalWindowStart: '08:00',
        arrivalWindowEnd: '08:30',
        maxWalk: WalkTime::fromMinutes(15),
        maxDetourMinutes: 10,
        budgetMonthlyPiastres: 160000,
        wantsReturnTrip: $return,
    ))->perSeatCeilingPiastres();

    // Twice the rides for the same money.
    expect($oneWay(true))->toBe((int) floor($oneWay(false) / 2));
});

it('treats no budget as no constraint', function () {
    $criteria = new SearchCriteria(
        origin: new Coordinate(30.0594, 31.4913),
        destination: new Coordinate(30.0714, 30.9716),
        days: DaysMask::weekdaysSunToThu(),
        arrivalWindowStart: '08:00',
        arrivalWindowEnd: '08:30',
        maxWalk: WalkTime::fromMinutes(15),
        maxDetourMinutes: 10,
    );

    // A passenger who named no budget is not disappointed by any price.
    expect($criteria->perSeatCeilingPiastres())->toBeNull();
});

it('defaults the flexibility rather than leaving it null', function () {
    $demand = saveDemand($this->paxToken)->assertStatus(201)->json('data');

    // The column defaults to 15 and so does the criteria, so the two cannot disagree.
    expect($demand['flexibilityMinutes'])->toBe(15)
        ->and($demand['wantsReturnTrip'])->toBeFalse();
});

it('refuses a flexibility wider than an hour', function () {
    saveDemand($this->paxToken, ['flexibilityMinutes' => 300])->assertStatus(422);
});
