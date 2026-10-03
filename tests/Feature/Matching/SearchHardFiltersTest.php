<?php

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserStat;
use App\Domains\Safety\Models\BlockedUser;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * 🔴 Stage one of the search: everything a passenger may not see, removed in SQL
 * before a single score is computed.
 *
 * These are the most important tests in the phase. Pitfall #15 is a hard
 * restriction implemented as a score penalty — a man appearing in a women-only
 * group's results with six points out of ten instead of ten. That is a security
 * breach, not a ranking quirk, and every case here asserts EXCLUSION rather than
 * a lower position.
 */
beforeEach(function () {
    Storage::fake('documents');

    // A published women-only commute from Rehab to Smart Village, Sunday to
    // Thursday at 07:05 — the Bible's reference journey.
    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->driverUserId = DriverProfile::sole()->user_id;
});

it('finds a published commute that matches', function () {
    $token = passenger('01112223344');

    $results = search($token)->assertOk()->json('data');

    expect($results)->toHaveCount(1)
        ->and($results[0]['commuteId'])->toBe($this->commuteId)
        ->and($results[0]['score']['total'])->toBeGreaterThan(0);
});

/**
 * 🔴 Pitfall #15, the single most important assertion in the phase.
 */
it('excludes men entirely from a women-only commute, not merely ranks them lower', function () {
    $token = passenger('01112223344', gender: 'man');

    $response = search($token)->assertOk();

    // Excluded, not present with a lower score. A result with ANY score would
    // mean a man can see a women-only group.
    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.matched'))->toBe(0)
        ->and($response->getContent())->not->toContain($this->commuteId);
});

it('also excludes someone who preferred not to state a gender', function () {
    $token = passenger('01112223344', gender: 'prefer_not_to_say');

    expect(search($token)->assertOk()->json('data'))->toBe([]);
});

it('cannot be tricked into eligibility by what the request claims', function () {
    $token = passenger('01112223344', gender: 'man');

    // Eligibility is read from the account, never from the request: a filter that
    // trusted a submitted value would be no filter at all.
    expect(search($token, ['audiencePreference' => 'women_only'])->assertOk()->json('data'))->toBe([]);
});

it('shows a commute open to any verified member to everyone', function () {
    CommuteOffer::sole()->forceFill(['audience' => 'any_verified'])->save();

    foreach (['woman', 'man', 'prefer_not_to_say'] as $index => $gender) {
        $token = passenger('0111222334'.$index, gender: $gender, device: "pax-{$index}");

        expect(search($token)->assertOk()->json('data'))->toHaveCount(1);
    }
});

/**
 * 🔴 Pitfall #27: a block is checked in BOTH directions. Looking only at who the
 * passenger blocked misses the case that matters more.
 */
it('hides a commute from a passenger the driver blocked', function () {
    $token = passenger('01112223344');
    $passengerId = User::query()->where('phone_e164', '+201112223344')->sole()->id;

    BlockedUser::create([
        'blocker_user_id' => $this->driverUserId,
        'blocked_user_id' => $passengerId,
    ]);

    expect(search($token)->assertOk()->json('data'))->toBe([]);
});

it('hides a commute from a passenger who blocked the driver', function () {
    $token = passenger('01112223344');
    $passengerId = User::query()->where('phone_e164', '+201112223344')->sole()->id;

    BlockedUser::create([
        'blocker_user_id' => $passengerId,
        'blocked_user_id' => $this->driverUserId,
    ]);

    expect(search($token)->assertOk()->json('data'))->toBe([]);
});

it('excludes a commute that is not published', function (string $status) {
    $token = passenger('01112223344');

    CommuteOffer::sole()->forceFill(['status' => $status])->save();

    expect(search($token)->assertOk()->json('data'))->toBe([]);
})->with(['draft', 'paused', 'archived']);

/**
 * A commute whose driver has since been suspended is not bookable however good
 * its score — and the offer and the driver can be out of step for as long as it
 * takes a sweep to run.
 */
it('excludes a commute whose driver is no longer approved', function () {
    $token = passenger('01112223344');

    DriverProfile::sole()->forceFill(['status' => DriverProfileStatus::Suspended->value])->save();

    expect(search($token)->assertOk()->json('data'))->toBe([]);
});

it('excludes a commute whose driver licence has lapsed', function () {
    $token = passenger('01112223344');

    DriverProfile::sole()->forceFill([
        'licence_expiry' => CarbonImmutable::yesterday()->toDateString(),
    ])->save();

    expect(search($token)->assertOk()->json('data'))->toBe([]);
});

it('excludes days with no seats left', function () {
    $token = passenger('01112223344');

    ScheduledTrip::query()->update(['seats_taken' => 3]);

    expect(search($token)->assertOk()->json('data'))->toBe([]);
});

it('excludes a commute that cannot seat the whole party', function () {
    $token = passenger('01112223344');

    ScheduledTrip::query()->update(['seats_taken' => 2]);

    // One seat left, three needed.
    expect(search($token, ['seatsNeeded' => 3])->assertOk()->json('data'))->toBe([])
        ->and(search($token, ['seatsNeeded' => 1])->assertOk()->json('data'))->toHaveCount(1);
});

it('excludes a commute that does not run on the days asked for', function () {
    $token = passenger('01112223344');

    // Friday only, against a Sunday-to-Thursday commute.
    expect(search($token, ['daysMask' => DaysMask::FRIDAY])->assertOk()->json('data'))->toBe([]);
});

it('excludes a commute whose route is nowhere near the journey', function () {
    $token = passenger('01112223344');

    expect(search($token, [
        // Alexandria to Port Said — well outside the commute's bounding box.
        'origin' => ['lat' => 31.2001, 'lng' => 29.9187],
        'destination' => ['lat' => 31.2650, 'lng' => 32.3019],
    ])->assertOk()->json('data'))->toBe([]);
});

it('excludes a commute whose meeting point is further than the passenger will walk', function () {
    $token = passenger('01112223344');

    // The commute's origin is at Rehab; this passenger starts 6km away inside the
    // bounding box, so stage one keeps it and stage two removes it.
    expect(search($token, [
        'origin' => ['lat' => 30.1100, 'lng' => 31.4700],
        'maxWalkMinutes' => 10,
    ])->assertOk()->json('data'))->toBe([]);
});

it('excludes a commute whose driver requires a higher trust level', function () {
    $token = passenger('01112223344');

    CommuteOffer::sole()->forceFill(['min_trust_level' => 4])->save();

    // The passenger has only their phone verified, so level 1.
    expect(search($token)->assertOk()->json('data'))->toBe([]);
});

it('excludes days whose booking deadline has passed', function () {
    $token = passenger('01112223344');

    ScheduledTrip::query()->update(['booking_deadline_at' => CarbonImmutable::now()->subHour()]);

    expect(search($token)->assertOk()->json('data'))->toBe([]);
});

it('never reveals the driver full name, phone number or gender', function () {
    $token = passenger('01112223344');

    $response = search($token)->assertOk();
    $driver = User::query()->whereKey($this->driverUserId)->sole();

    // The public name is asserted on the DECODED payload: an Arabic name is
    // escaped to `م...` in the raw body, so a substring check against the
    // UTF-8 original would fail for an encoding reason rather than a real one.
    expect($response->json('data.0.driver.publicFirstName'))->toBe($driver->public_first_name);

    // The absences are checked on the raw body, where they would appear as plain
    // ASCII either way — and where nothing can hide behind a nested structure.
    expect($response->getContent())->not->toContain($driver->getRawOriginal('phone_e164'))
        ->and($response->getContent())->not->toContain('gender')
        ->and($response->getContent())->not->toContain('fullName');
});

/**
 * The full road a driver takes every day, handed to anyone who searches, is a
 * movement pattern nobody agreed to publish.
 */
it('never reveals the commute route polyline in a search result', function () {
    $token = passenger('01112223344');

    $polyline = CommuteOffer::sole()->route_polyline;

    expect($polyline)->not->toBeNull()
        ->and(search($token)->assertOk()->getContent())->not->toContain($polyline);
});

it('rate limits searching, as an unbounded search endpoint spends provider money', function () {
    $token = passenger('01112223344');

    $limit = (int) config('rafeeq.matching.searches_per_user_per_minute');

    foreach (range(1, $limit) as $ignored) {
        search($token)->assertOk();
    }

    search($token)->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')
        ->assertHeader('Retry-After');
});

it('requires a complete profile but not verification, so the product can be evaluated', function () {
    fakeOtpSender();
    $token = signIn(phone: '01223334455', devicePublicId: 'fresh')['session']['accessToken'];

    // No profile yet.
    search($token)->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_PROFILE_INCOMPLETE');

    completeBasicProfile($token);

    // Profile complete, identity unverified — searching is allowed.
    search($token)->assertOk();
});

/*
|--------------------------------------------------------------------------
| The passenger's minimum rating (Phase 10)
|--------------------------------------------------------------------------
|
| 🔴 A HARD filter, per the Bible's own rule that "hard conflicts never receive a soft score".
| Somebody who says she will not ride with anyone under four stars is stating a condition, not a
| preference — scoring it would put a 3.1-star driver in her results, ranked lower, which is the
| same class of mistake as scoring a women-only breach instead of excluding it.
*/

it('excludes a driver rated below the passenger minimum', function () {
    setUserStat($this->driverUserId, ['avg_rating_as_driver' => 3.10]);

    $token = passenger('01112223344');

    expect(search($token, ['minRating' => 4])->assertOk()->json('data'))->toBe([]);

    // And without the filter she is found, so the test is about the filter and not the setup.
    expect(search($token)->assertOk()->json('data'))->toHaveCount(1);
});

it('keeps a driver rated at the minimum', function () {
    setUserStat($this->driverUserId, ['avg_rating_as_driver' => 4.00]);

    // At, not above: the boundary is a minimum, and excluding somebody who meets it exactly is
    // the off-by-one that would quietly hide every driver sitting on a round number.
    expect(search(passenger('01112223344'), ['minRating' => 4])->assertOk()->json('data'))
        ->toHaveCount(1);
});

/**
 * 🔒 The decision in this filter, and the one worth defending.
 *
 * `null` means "nobody has rated her yet", never zero — the project says so everywhere a rate is
 * returned. Excluding unrated drivers would hide EVERY new driver from EVERY filtered search: a
 * cold start that starves the platform of supply, and an untrue answer, because nothing bad has
 * been said about her.
 */
it('keeps a driver nobody has rated yet', function () {
    setUserStat($this->driverUserId, ['avg_rating_as_driver' => null]);

    expect(search(passenger('01112223344'), ['minRating' => 5])->assertOk()->json('data'))
        ->toHaveCount(1);
});

/**
 * The same case one step earlier: a driver who has not completed a trip has no `user_stats` row at
 * all, because the row is written when one completes. A `whereHas` on the stats row would have
 * excluded her — which is why the filter is written as "there is no evidence she is below your
 * bar" rather than "her rating is at or above it".
 */
it('keeps a driver who has no stats row at all', function () {
    UserStat::query()->where('user_id', $this->driverUserId)->delete();

    expect(UserStat::query()->where('user_id', $this->driverUserId)->exists())->toBeFalse();

    expect(search(passenger('01112223344'), ['minRating' => 5])->assertOk()->json('data'))
        ->toHaveCount(1);
});
