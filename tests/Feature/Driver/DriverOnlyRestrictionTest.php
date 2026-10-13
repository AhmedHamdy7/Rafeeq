<?php

use App\Domains\Admin\Actions\SuspendMemberAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\SuspensionReason;
use App\Domains\Identity\Models\User;

/**
 * Feature 01 v3 §8, stated as tests: "Admin may restrict **Driver capability only** or **entire
 * account** based on case. Account-level restriction shows Restricted Account + support;
 * driver-only restriction preserves passenger access."
 *
 * 🔴 Written because v3 proposes an `account_restrictions` table with a `scope` column to express
 * this, and both halves turn out to exist already in two tables that each own their own:
 *
 *   - account-wide → `account_suspensions` + `users.account_status`, via `SuspendMemberAction`
 *   - driver only  → `driver_profiles.status`, via `ReviewDriverApplicationAction::suspend()`
 *
 * A `scope` column was added and removed again before it shipped, because it would have been a
 * SECOND place a person can be driver-restricted and the first code path to check only one of them
 * would be a hole somebody walks through.
 *
 * What was genuinely missing was not a mechanism but this file. The requirement was satisfied by
 * four independent refusals in different domains, and nothing asserted that they add up to the
 * promise — so any one of them could have been relaxed without a failing test.
 */
function suspendedDriver(): string
{
    $token = approvedDriver();

    app(ReviewDriverApplicationAction::class)->suspend(
        DriverProfile::sole(),
        adminWithRole(AdminRole::SafetyLead),
        'Two safety reports in one week, holding while we look.',
    );

    return $token;
}

/**
 * 🔴 The half of §8 that is easy to get wrong, because the lazy implementation — flipping
 * `account_status` — passes every driver test and silently destroys this.
 */
it('leaves a driver-only restriction out of the account state entirely', function () {
    $token = suspendedDriver();

    expect(User::sole()->account_status)->toBe(AccountStatus::Active);

    // She is signed in, and the server routes her HOME rather than to the restricted screen.
    test()->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.nextStep', 'HOME')
        ->assertJsonPath('data.user.accountStatus', 'ACTIVE');
});

/**
 * "Driver-only restriction preserves passenger access" — the whole point. A restriction about
 * driving must not take away the commute she rides to work on.
 */
it('leaves her able to use Rafeeq as a passenger', function () {
    $token = suspendedDriver();

    // The passenger surfaces that `account.active` would have refused outright.
    test()->withToken($token)->getJson('/api/v1/home')->assertOk();
    test()->withToken($token)->getJson('/api/v1/my-bookings')->assertOk();
    test()->withToken($token)->getJson('/api/v1/account/stats')->assertOk();
    search($token)->assertOk();
});

/**
 * "Protected driver actions fail." Each of these refuses on its own, in a different domain, which
 * is why they are asserted separately rather than trusting one gate to cover the rest.
 */
it('refuses the driver actions', function () {
    $token = suspendedDriver();
    $vehicleId = test()->withToken($token)->getJson('/api/v1/driver/vehicles')->json('data.0.id');

    createCommute($token, $vehicleId)->assertStatus(403);
});

/**
 * "Driver UI shows restriction." The app has to be able to SAY why the driver side is closed, or
 * the person just meets a dead button.
 */
it('tells her why, with something the screen can act on', function () {
    $token = suspendedDriver();

    $response = test()->withToken($token)->getJson('/api/v1/driver/eligibility')->assertOk();

    expect($response->json('data.eligible'))->toBeFalse()
        ->and(collect($response->json('data.blockers'))->pluck('reason'))->toContain('driver_suspended')
        // A next step rather than a bare reason string, so the screen never maps one to a button
        // itself and gets it wrong.
        ->and(collect($response->json('data.blockers'))->firstWhere('reason', 'driver_suspended'))
        ->toHaveKeys(['reason', 'nextStep', 'message']);
});

/**
 * Her commutes must stop being offered to passengers. Otherwise somebody books a seat with a
 * driver the safety team has just stopped — the restriction would exist on paper only.
 */
it('takes her commutes out of what passengers can find', function () {
    $driverToken = approvedDriver();
    $vehicleId = test()->withToken($driverToken)->getJson('/api/v1/driver/vehicles')->json('data.0.id');
    $commuteId = readyCommute($driverToken, $vehicleId);

    // `readyCommute` stops one step short of publishing, and an unpublished commute is invisible
    // to search anyway — so without this the test would "pass" for the wrong reason.
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $paxToken = verifiedPassenger('01099887766');
    expect(search($paxToken)->json('data'))->not->toBeEmpty();

    app(ReviewDriverApplicationAction::class)->suspend(
        DriverProfile::sole(),
        adminWithRole(AdminRole::SafetyLead),
        'Holding while we look.',
    );

    expect(search($paxToken)->json('data'))->toBeEmpty();
});

/**
 * And the other half of §8, for contrast: an ACCOUNT restriction does take everything, and says so
 * with a case number the person can quote.
 */
it('closes everything for an account-wide restriction, with a reference to quote', function () {
    $token = approvedDriver();

    $hold = app(SuspendMemberAction::class)->suspend(
        User::sole(),
        adminWithRole(AdminRole::Operations),
        SuspensionReason::SafetyReport,
        'Two reports this week, holding for review.',
    );

    expect(User::sole()->account_status)->toBe(AccountStatus::Suspended);

    test()->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.nextStep', 'RESTRICTED_ACCOUNT')
        ->assertJsonPath('data.user.suspension.caseNumber', $hold->case_number);

    // Even the passenger side is closed now, which is the difference from the driver-only case.
    search($token)->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');

    /*
     * ⚠️ `GET /home` stays open, and that is deliberate rather than a gap — it is the screen that
     * TELLS her she is restricted, so gating it behind the thing it explains would leave her
     * staring at a dead app with no reason given. Pinned here because it looks like a hole to
     * anybody reading the route list, and because an "obvious" tightening of it would break
     * screen 35.
     */
    test()->withToken($token)->getJson('/api/v1/home')->assertOk();
});
