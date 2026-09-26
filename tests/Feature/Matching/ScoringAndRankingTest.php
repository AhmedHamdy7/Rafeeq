<?php

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Matching\Models\MatchScore;
use Illuminate\Support\Facades\Storage;

/**
 * The 100-point model (Bible §Part 2, scene 4.3) and the ranking it produces.
 *
 * The weights are what Rafeeq IS: overlap and schedule together are more than
 * half, because a commute not going the passenger's way at the time they need is
 * not a match however pleasant; price is worth five, because this is shared cost
 * and not a marketplace.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->paxToken = passenger('01112223344');
});

it('gives a perfect score to a journey that matches on every count', function () {
    $result = search($this->paxToken)->assertOk()->json('data.0');

    // Same origin, same destination, inside the window, no detour, no unmet
    // preference, within budget, driver with no failures on record.
    expect($result['score'])->toBe([
        'total' => 100,
        'overlap' => 30,
        'schedule' => 25,
        'detour' => 15,
        'audience' => 10,
        'comfort' => 10,
        'price' => 5,
        'reliability' => 5,
    ]);
});

/**
 * Chapter 5 shows a match-details screen. A passenger choosing between two
 * commutes deserves to see WHY one ranked above the other, not a number to trust.
 */
it('returns the breakdown, not just a total', function () {
    $score = search($this->paxToken)->assertOk()->json('data.0.score');

    expect(array_keys($score))->toEqualCanonicalizing([
        'total', 'overlap', 'schedule', 'detour', 'audience', 'comfort', 'price', 'reliability',
    ])
        // And the parts really do add up to the whole, so the screen cannot show a
        // breakdown that contradicts the headline.
        ->and($score['total'])->toBe(
            $score['overlap'] + $score['schedule'] + $score['detour']
            + $score['audience'] + $score['comfort'] + $score['price'] + $score['reliability']
        );
});

it('loses schedule points for a departure outside the window', function () {
    $inWindow = search($this->paxToken)->assertOk()->json('data.0.score.schedule');

    // The commute leaves at 07:05; ask for 07:20–07:40, so it is fifteen minutes
    // early — half the thirty-minute span over which the score decays.
    $outOfWindow = search($this->paxToken, [
        'arrivalWindowStart' => '07:20:00',
        'arrivalWindowEnd' => '07:40:00',
    ])->assertOk()->json('data.0.score.schedule');

    expect($inWindow)->toBe(25)
        ->and($outOfWindow)->toBeLessThan(25)
        ->and($outOfWindow)->toBeGreaterThan(0);
});

it('scores nothing for timing half an hour out', function () {
    // A commuter who has to leave half an hour early every day has not been
    // matched, they have been inconvenienced.
    $score = search($this->paxToken, [
        'arrivalWindowStart' => '09:00:00',
        'arrivalWindowEnd' => '09:30:00',
    ])->assertOk()->json('data.0.score.schedule');

    expect($score)->toBe(0);
});

/**
 * The budget is a MONTHLY ceiling, so these figures are months and the per-ride ceiling is
 * derived from them.
 *
 * The commute costs 8,000 piastres a ride, and the search asks for five days a week one
 * way — about 21.7 rides a month (5 × 52/12). So a month's budget divided by 21.7 is what
 * the 8,000 is actually compared against:
 *
 *   200,000 ÷ 21.7 ≈ 9,200 → under the price, full marks
 *   130,000 ÷ 21.7 ≈ 6,000 → a third over, so it tapers
 *    87,000 ÷ 21.7 ≈ 4,000 → double the ceiling, worth nothing
 *
 * These used to be per-ride figures (10,000 / 5,000 / 2,000), which is the bug the monthly
 * correction fixed: read as months they are a few hundred piastres a ride, so every
 * commute was hopelessly over budget rather than comfortably under it.
 */
it('loses price points when the commute costs more than the budget', function () {
    $within = search($this->paxToken, ['budgetMonthlyPiastres' => 200000])
        ->assertOk()->json('data.0.score.price');

    $over = search($this->paxToken, ['budgetMonthlyPiastres' => 130000])
        ->assertOk()->json('data.0.score.price');

    $wayOver = search($this->paxToken, ['budgetMonthlyPiastres' => 87000])
        ->assertOk()->json('data.0.score.price');

    expect($within)->toBe(5)
        ->and($over)->toBeLessThan(5)
        // Twice the budget is worth nothing, but a commute slightly over still
        // ranks above one at double.
        ->and($wayOver)->toBe(0)
        ->and($over)->toBeGreaterThan($wayOver);
});

/**
 * 🔴 The bug the monthly correction fixed, pinned so it cannot come back.
 *
 * Read as a per-ride figure, a monthly budget is enormous — so every commute came in under
 * it and the five points for price were full marks for everybody, whatever they cost.
 */
it('does not hand out full price marks to a commute nobody could afford', function () {
    // 900 EGP a month over five days is about 41 a ride. The commute costs 80.
    $score = search($this->paxToken, ['budgetMonthlyPiastres' => 90000])
        ->assertOk()->json('data.0.score.price');

    expect($score)->toBeLessThan(5);
});

it('scores comfort by how many of the requested rules the commute actually has', function () {
    test()->withToken($this->driverToken)->patchJson("/api/v1/commutes/{$this->commuteId}", [
        'rules' => ['nonsmoking' => true, 'quiet' => true],
    ])->assertOk();

    $bothMet = search($this->paxToken, ['rules' => ['nonsmoking', 'quiet']])
        ->assertOk()->json('data.0.score.comfort');

    $oneMet = search($this->paxToken, ['rules' => ['nonsmoking', 'ac']])
        ->assertOk()->json('data.0.score.comfort');

    $noneMet = search($this->paxToken, ['rules' => ['ac', 'nofood']])
        ->assertOk()->json('data.0.score.comfort');

    expect($bothMet)->toBe(10)
        ->and($oneMet)->toBe(5)
        ->and($noneMet)->toBe(0);
});

it('does not penalise a passenger who asked for nothing', function () {
    // Someone who wanted no particular rules is not disappointed by a commute
    // that has none, so full marks rather than zero.
    expect(search($this->paxToken)->assertOk()->json('data.0.score.comfort'))->toBe(10);
});

/**
 * Trust is earned by evidence of failure, not withheld for lack of evidence of
 * success. Starting every new driver at zero would mean they are never matched,
 * so they never build the history that would let them be matched.
 */
it('gives a driver with no history the benefit of the doubt', function () {
    expect(DriverProfile::sole()->on_time_rate)->toBeNull()
        ->and(search($this->paxToken)->assertOk()->json('data.0.score.reliability'))->toBe(5);
});

it('scores reliability from the driver record once there is one', function () {
    DriverProfile::sole()->forceFill(['on_time_rate' => 40])->save();

    expect(search($this->paxToken)->assertOk()->json('data.0.score.reliability'))->toBe(2);
});

it('scores the audience preference without ever deciding eligibility with it', function () {
    // Asked for women-only and found it.
    expect(search($this->paxToken, ['audiencePreference' => 'women_only'])
        ->assertOk()->json('data.0.score.audience'))->toBe(10);

    CommuteOffer::sole()->forceFill(['audience' => 'any_verified'])->save();

    // Asked for women-only and this one is open to anyone: fewer points, still
    // visible. Eligibility was never in question — she is allowed on both.
    expect(search($this->paxToken, ['audiencePreference' => 'women_only'])
        ->assertOk()->json('data.0.score.audience'))->toBe(0);
});

/**
 * A recurring search over a month would otherwise return the same commute twenty
 * times, pushing every other driver off the first screen.
 */
it('returns one card per commute, with the other matching days alongside', function () {
    $results = search($this->paxToken)->assertOk()->json('data');

    expect($results)->toHaveCount(1)
        ->and($results[0]['otherDays'])->not->toBeEmpty()
        // The card itself is the soonest matching day.
        ->and($results[0]['tripDate'])->toBeLessThan($results[0]['otherDays'][0]['tripDate']);
});

it('ranks a better match above a worse one', function () {
    // A second commute on the same route but leaving an hour later, so it scores
    // lower on schedule and nothing else.
    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223334455', devicePublicId: 'driver-2', seed: 2);

    $otherVehicle = Vehicle::query()->where('plate_normalized', 'ABC1236')->sole();
    $later = createCommute($otherDriver, $otherVehicle->id)->assertStatus(201)->json('data.id');

    saveRoute($otherDriver, $later)->assertOk();
    saveSchedule($otherDriver, $later, ['departureTime' => '08:30:00'])->assertOk();

    test()->withToken($otherDriver)->postJson("/api/v1/commutes/{$later}/publish")->assertOk();

    $results = search($this->paxToken)->assertOk()->json('data');

    expect($results)->toHaveCount(2)
        ->and($results[0]['commuteId'])->toBe($this->commuteId)
        ->and($results[0]['score']['total'])->toBeGreaterThan($results[1]['score']['total']);
});

/**
 * Bible §7.3: match results are cached for an hour. A passenger refining filters
 * re-searches repeatedly, and the same question should not re-run four stages.
 */
it('caches the result breakdown for later reuse', function () {
    search($this->paxToken)->assertOk();

    $cached = MatchScore::query()->get();

    expect($cached)->not->toBeEmpty()
        ->and($cached->first()->total)->toBe(100)
        ->and($cached->first()->expires_at->isFuture())->toBeTrue()
        // An hour, because seats move: longer and the cache would offer days that
        // have filled up.
        ->and($cached->first()->expires_at->diffInMinutes(now()->addHour(), absolute: true))
        ->toBeLessThan(2);
});

it('does not pile up duplicate cache rows when the same search runs twice', function () {
    search($this->paxToken)->assertOk();
    $first = MatchScore::count();

    search($this->paxToken)->assertOk();

    expect(MatchScore::count())->toBe($first);
});

it('keys the cache per person, since eligibility differs between them', function () {
    search($this->paxToken)->assertOk();
    $signatures = MatchScore::query()->distinct()->pluck('demand_signature');

    $otherPax = passenger('01223339999', device: 'pax-2');
    search($otherPax)->assertOk();

    // Two people with identical criteria see different results if one is blocked
    // by a driver the other is not; sharing a cache row would leak that.
    expect(MatchScore::query()->distinct()->pluck('demand_signature')->count())
        ->toBeGreaterThan($signatures->count());
});

/**
 * Chapter 5's edge case: "commute becomes full after search". A cache hit must
 * not replay a day that filled up in the meantime.
 */
it('stops offering a day that filled up after it was cached', function () {
    search($this->paxToken)->assertOk();

    ScheduledTrip::query()->update(['seats_taken' => 3]);

    expect(search($this->paxToken)->assertOk()->json('data'))->toBe([]);
});
