<?php

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserStat;
use App\Domains\Verification\Enums\VerificationType;
use Illuminate\Support\Facades\Storage;

/**
 * The profile screen's numbers (screen 21), and the shared person summary that carries a
 * subset of them onto the match card, the member list and the driver's request review.
 *
 * `user_stats` was added in Phase 1 for exactly this screen (ERD §23.1 gap #2) and then
 * nothing ever returned it, so the screen has been unbuildable ever since.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->token = verifiedPassenger('01112223344');
});

/**
 * Writes a stats row the way the recomputing job will.
 *
 * Attribute by attribute, not `updateOrCreate`: `UserStat` deliberately has no fillable
 * list because the table is only ever written by system jobs and never from user input, so
 * mass assignment is refused — correctly. A test that mass-assigned it would be exercising
 * a path production does not have.
 *
 * @param  array<string, mixed>  $figures
 */
function recomputeStatsFor(User $user, array $figures): UserStat
{
    $stats = UserStat::query()->find($user->id) ?? new UserStat;

    $stats->user_id = $user->id;

    foreach ($figures as $column => $value) {
        $stats->{$column} = $value;
    }

    $stats->save();

    return $stats;
}

/**
 * 🔴 The distinction this resource is built around.
 *
 * A new passenger has no on-time rate. Sending 0 would put "0% on-time" on their profile
 * and in front of every driver deciding whether to let them into a car — a reliability
 * accusation earned by nobody, on their first day.
 */
it('reports nothing-yet as null, never as zero', function () {
    $stats = test()->withToken($this->token)->getJson('/api/v1/account/stats')
        ->assertOk()->json('data');

    expect($stats['onTimeRate'])->toBeNull()
        ->and($stats['ratingAsPassenger'])->toBeNull()
        ->and($stats['ratingAsDriver'])->toBeNull()
        ->and($stats['cancellationRate'])->toBeNull()
        // Counts are the opposite: "no trips yet" and "zero trips" are the same thing.
        ->and($stats['completedTripsAsPassenger'])->toBe(0)
        ->and($stats['noShowCount'])->toBe(0)
        // Nothing has been computed, so there is no "as of" to show.
        ->and($stats['computedAt'])->toBeNull();
});

it('reports the figures once a job has computed them', function () {
    $user = User::query()->where('phone_e164', '+201112223344')->sole();

    recomputeStatsFor($user, [
        'completed_trips_as_passenger' => 12,
        'avg_rating_as_passenger' => 4.9,
        'on_time_rate' => 96.0,
        'computed_at' => now(),
    ]);

    $stats = test()->withToken($this->token)->getJson('/api/v1/account/stats')
        ->assertOk()->json('data');

    /*
     * Compared as numbers, not identically. `96.0` goes over the wire as `96` because
     * PHP's json_encode drops a zero fraction — the same thing the contract's own
     * "Numbers" section warns the mobile team about, and which this test walked straight
     * into.
     */
    expect($stats['completedTripsAsPassenger'])->toBe(12)
        // The exact three figures screen 21 shows.
        ->and((float) $stats['ratingAsPassenger'])->toBe(4.9)
        ->and((float) $stats['onTimeRate'])->toBe(96.0);
});

/**
 * The decimal cast returns a string, and a rating that arrives as "4.90" is the sort of
 * field a client parses once and then multiplies by accident.
 */
it('sends the rates as numbers, not as decimal strings', function () {
    $user = User::query()->where('phone_e164', '+201112223344')->sole();

    recomputeStatsFor($user, ['avg_rating_as_passenger' => 4.5]);

    $rating = test()->withToken($this->token)->getJson('/api/v1/account/stats')
        ->assertOk()->json('data.ratingAsPassenger');

    expect($rating)->toBeNumeric()->and($rating)->not->toBeString();
});

it('returns only the caller, with no way to ask for anybody else', function () {
    verifiedPassenger('01222220001', device: 'pax-2');
    $otherUser = User::query()->where('phone_e164', '+201222220001')->sole();

    recomputeStatsFor($otherUser, ['completed_trips_as_passenger' => 99]);

    /*
     * 🔒 There is no `/account/stats/{user}` and there must not be: it would make the
     * platform's whole membership enumerable by anybody with an account. A user id in the
     * query string is simply ignored.
     */
    test()->withToken($this->token)
        ->getJson("/api/v1/account/stats?user={$otherUser->id}&userId={$otherUser->id}")
        ->assertOk()
        ->assertJsonPath('data.completedTripsAsPassenger', 0);
});

it('needs a signed-in account', function () {
    test()->withoutToken()->getJson('/api/v1/account/stats')->assertStatus(401);
});

/**
 * Reachable while suspended, like the rest of the account group: somebody appealing a
 * suspension can still see their own record.
 */
it('names which levels were verified, not just how many', function () {
    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);

    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    requestSeat($this->token, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201);

    $passenger = test()->withToken($driverToken)->getJson('/api/v1/driver/seat-requests')
        ->assertOk()->json('data.0.passenger');

    /*
     * WHICH levels, not just the count. A trust level of 2 does not say which two, so a
     * client given only the number has to guess — and guesses wrong the moment the levels
     * are reordered. Screen 28 shows named badges.
     */
    expect($passenger['verifiedLevels'])->toContain(VerificationType::GovernmentId->value)
        ->and($passenger['trustLevel'])->toBeGreaterThan(0)
        // The driver is deciding who rides with them, so they see the numbers too.
        ->and($passenger)->toHaveKeys(['rating', 'completedTrips', 'onTimeRate']);

    // 🔒 And still nothing more than that.
    expect($passenger)->not->toHaveKey('fullName')
        ->and($passenger)->not->toHaveKey('phoneE164')
        ->and($passenger)->not->toHaveKey('email')
        ->and($passenger)->not->toHaveKey('gender')
        ->and($passenger)->not->toHaveKey('dateOfBirth');
});

/**
 * 🔴 "Same workplace" is a COMPARISON, never a disclosure.
 *
 * A badge naming the employer would tell every passenger on the platform where a driver
 * works, which is most of what somebody needs to wait for her outside it.
 */
it('says whether two people share an employer without naming it', function () {
    $driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);

    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    requestSeat($this->token, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201);

    $body = test()->withToken($driverToken)->getJson('/api/v1/driver/seat-requests')
        ->assertOk()->json('data.0.passenger');

    expect($body)->toHaveKey('sameOrganisation')
        ->and($body['sameOrganisation'])->toBeBool()
        // The organisation itself is never in the payload.
        ->and($body)->not->toHaveKey('organizationId')
        ->and($body)->not->toHaveKey('organisation')
        ->and($body)->not->toHaveKey('employer');
});
