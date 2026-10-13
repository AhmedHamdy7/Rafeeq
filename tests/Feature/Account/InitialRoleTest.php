<?php

use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\CapabilityStatus;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserCapability;
use App\Domains\Identity\Models\UserPreference;

/**
 * Feature 01 v3 §§4–5: choosing Passenger / Driver / Both as its own step, and resuming a sign-up
 * that was interrupted before it.
 */
function completeProfileWithoutRole(string $accessToken)
{
    return test()->withToken($accessToken)->putJson('/api/v1/account/profile/basic', [
        'fullName' => 'مريم حسن',
        'gender' => 'woman',
        'dateOfBirth' => '1996-04-12',
    ]);
}

function chooseRole(string $accessToken, string $role)
{
    return test()->withToken($accessToken)
        ->postJson('/api/v1/account/initial-role', ['role' => $role]);
}

it('asks for a role when the profile arrives without one', function () {
    $signIn = signIn();

    $response = completeProfileWithoutRole($signIn['session']['accessToken'])->assertOk();

    expect($response->json('data.nextStep'))->toBe('ROLE_SELECTION')
        ->and($response->json('data.user.profileStatus'))->toBe('BASIC_COMPLETE')
        ->and($response->json('data.user.initialRoleSelected'))->toBeFalse()
        ->and($response->json('data.user.capabilities'))->toBe([])
        // 🔴 The profile really is complete in the database without a role. The old CHECK
        // constraint made that impossible, which is why the two-step flow needed a migration
        // rather than just an endpoint.
        ->and(User::sole()->profile_status)->toBe(ProfileStatus::BasicComplete)
        ->and(User::sole()->initial_role_selected_at)->toBeNull();
});

/**
 * 🔴 The compatibility promise. Every client live today sends `registeredRole` with the profile,
 * and must keep going straight Home — otherwise this is a flag-day change for the mobile team
 * rather than an additive one.
 */
it('still finishes in one step for a client that sends the role with the profile', function () {
    $signIn = signIn();

    $response = test()->withToken($signIn['session']['accessToken'])
        ->putJson('/api/v1/account/profile/basic', [
            'fullName' => 'مريم حسن',
            'gender' => 'woman',
            'registeredRole' => 'passenger',
        ])->assertOk();

    expect($response->json('data.nextStep'))->toBe('HOME')
        ->and($response->json('data.user.capabilities'))->toBe(['PASSENGER'])
        ->and(User::sole()->initial_role_selected_at)->not->toBeNull();
});

it('enrols one capability for a single role', function (string $role, array $expected) {
    $signIn = signIn();
    completeProfileWithoutRole($signIn['session']['accessToken'])->assertOk();

    $response = chooseRole($signIn['session']['accessToken'], $role)->assertOk();

    expect($response->json('data.nextStep'))->toBe('HOME')
        ->and($response->json('data.user.capabilities'))->toBe($expected)
        ->and(UserCapability::pluck('capability')->map(fn ($c) => $c->value)->sort()->values()->all())
        ->toBe(collect($expected)->map(fn (string $v) => strtolower($v))->sort()->values()->all());
})->with([
    ['passenger', ['PASSENGER']],
    ['driver', ['DRIVER']],
    ['both', ['PASSENGER', 'DRIVER']],
]);

/**
 * Feature 01 v3 §4: "Both → Passenger Home; Driver walkthrough only on first Driver activation."
 */
it('starts somebody who chose Both on the passenger side', function () {
    $signIn = signIn();
    completeProfileWithoutRole($signIn['session']['accessToken'])->assertOk();

    chooseRole($signIn['session']['accessToken'], 'both')->assertOk();

    expect(UserCapability::count())->toBe(2)
        ->and(UserPreference::sole()->last_active_mode)->toBe(Capability::Passenger)
        // The walkthrough has NOT been seen — it runs when the driver side is first opened.
        ->and(UserPreference::sole()->needsDriverWalkthrough())->toBeTrue();
});

/**
 * 🔴 The acceptance criterion, stated as a test: "Repeat role submit does not create duplicate
 * capability rows."
 *
 * This is the last call of a registration on a mobile network, so a retry after a timeout that
 * already committed is the normal case rather than an edge one.
 */
it('is idempotent however many times the app retries it', function () {
    $signIn = signIn();
    completeProfileWithoutRole($signIn['session']['accessToken'])->assertOk();

    chooseRole($signIn['session']['accessToken'], 'both')->assertOk();
    $selectedAt = User::sole()->initial_role_selected_at;

    $this->travel(5)->minutes();

    chooseRole($signIn['session']['accessToken'], 'both')->assertOk();

    expect(UserCapability::count())->toBe(2)
        // And the original timestamp is untouched, so "when did she choose" stays answerable.
        ->and(User::sole()->initial_role_selected_at->timestamp)->toBe($selectedAt->timestamp);
});

/**
 * Adding driving later must not rewrite what she originally signed up as.
 */
it('adds a capability without rewriting the role declared at sign-up', function () {
    $signIn = signIn();
    completeProfileWithoutRole($signIn['session']['accessToken'])->assertOk();

    chooseRole($signIn['session']['accessToken'], 'passenger')->assertOk();
    $response = chooseRole($signIn['session']['accessToken'], 'both')->assertOk();

    expect($response->json('data.user.capabilities'))->toContain('PASSENGER', 'DRIVER')
        // `registeredRole` is history: she came here as a passenger and that stays true.
        ->and(User::sole()->registered_role->value)->toBe('passenger');
});

/**
 * 🔴 The anti-loop guarantee, and the subtlest rule in this feature.
 *
 * A cold start with the profile saved but no role answers BASIC_PROFILE, not ROLE_SELECTION
 * (F01 §5: resume at Basic Profile "even if profile fields are already populated"). If the
 * PROFILE endpoint followed that same rule it would tell the app to submit the profile it just
 * submitted, for ever — which is why the two call sites deliberately disagree.
 */
it('resumes an interrupted sign-up at the profile, never straight on Role', function () {
    $signIn = signIn();
    completeProfileWithoutRole($signIn['session']['accessToken'])->assertOk();

    // Cold start: the app was killed on the role screen and asks again where to go.
    $this->withToken($signIn['session']['accessToken'])->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.nextStep', 'BASIC_PROFILE');

    $this->withToken($signIn['session']['accessToken'])->getJson('/api/v1/account/onboarding-state')
        ->assertOk()
        ->assertJsonPath('data.nextStep', 'BASIC_PROFILE')
        ->assertJsonPath('data.profileComplete', true)
        ->assertJsonPath('data.initialRoleSelected', false);

    // And re-submitting the profile moves forward rather than round in a circle.
    expect(completeProfileWithoutRole($signIn['session']['accessToken'])->json('data.nextStep'))
        ->toBe('ROLE_SELECTION');
});

it('reports where a brand-new account should start', function () {
    $signIn = signIn();

    $this->withToken($signIn['session']['accessToken'])->getJson('/api/v1/account/onboarding-state')
        ->assertOk()
        ->assertJsonPath('data.nextStep', 'BASIC_PROFILE')
        ->assertJsonPath('data.profileComplete', false)
        ->assertJsonPath('data.onboardingSeen', false)
        ->assertJsonPath('data.activeMode', null);
});

/**
 * 🔒 The state the endpoint exists to describe is also the state it must survive: a suspended
 * account has to be able to learn WHY it is stuck.
 */
it('tells a restricted account that it is restricted rather than refusing to answer', function () {
    $signIn = signIn();
    User::sole()->forceFill(['account_status' => AccountStatus::Suspended->value])->save();

    $this->withToken($signIn['session']['accessToken'])->getJson('/api/v1/account/onboarding-state')
        ->assertOk()->assertJsonPath('data.nextStep', 'RESTRICTED_ACCOUNT');
});

it('refuses to enrol a role while the account is suspended', function () {
    $signIn = signIn();
    completeProfileWithoutRole($signIn['session']['accessToken'])->assertOk();

    User::sole()->forceFill(['account_status' => AccountStatus::Suspended->value])->save();

    chooseRole($signIn['session']['accessToken'], 'driver')
        ->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');

    expect(UserCapability::count())->toBe(0);
});

it('sends somebody who skipped the profile back to it rather than enrolling a role', function () {
    $signIn = signIn();

    chooseRole($signIn['session']['accessToken'], 'driver')->assertStatus(403);

    expect(UserCapability::count())->toBe(0);
});

it('rejects a role it has never heard of', function () {
    $signIn = signIn();
    completeProfileWithoutRole($signIn['session']['accessToken'])->assertOk();

    chooseRole($signIn['session']['accessToken'], 'administrator')
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    expect(UserCapability::count())->toBe(0);
});

/**
 * 🔒 Feature 01 v3: preferences are updatable "without granting capabilities", and the server
 * validates capability per request rather than trusting a client flag.
 */
it('stores a driving mode without granting the driver capability', function () {
    $signIn = signIn();
    completeProfileWithoutRole($signIn['session']['accessToken'])->assertOk();
    chooseRole($signIn['session']['accessToken'], 'passenger')->assertOk();

    $this->withToken($signIn['session']['accessToken'])
        ->patchJson('/api/v1/account/preferences', ['activeMode' => 'driver'])
        ->assertOk()->assertJsonPath('data.activeMode', 'driver');

    // The preference moved. The capability did not.
    expect(UserPreference::sole()->last_active_mode)->toBe(Capability::Driver)
        ->and(UserCapability::pluck('capability')->map(fn ($c) => $c->value)->all())->toBe(['passenger']);

    $this->withToken($signIn['session']['accessToken'])->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.user.capabilities', ['PASSENGER']);
});

it('records the intro slides and the walkthrough as seen, once and for all', function () {
    $signIn = signIn();

    $this->withToken($signIn['session']['accessToken'])
        ->patchJson('/api/v1/account/preferences', [
            'onboardingSeen' => true,
            'driverWalkthroughSeen' => true,
            'language' => 'ar',
            'theme' => 'dark',
        ])->assertOk()
        ->assertJsonPath('data.onboardingSeen', true)
        ->assertJsonPath('data.driverWalkthroughSeen', true)
        ->assertJsonPath('data.language', 'ar')
        ->assertJsonPath('data.theme', 'dark');

    $firstSeen = UserPreference::sole()->onboarding_seen_at;

    // Minutes, not a day: the access token's lifetime is measured in minutes, so travelling
    // further would expire it and this would fail on a 401 instead of testing the timestamp.
    $this->travel(2)->minutes();

    $this->withToken($signIn['session']['accessToken'])
        ->patchJson('/api/v1/account/preferences', ['onboardingSeen' => true])->assertOk();

    /*
     * 🔴 The date must NOT move. It is what tells support whether somebody had seen the safety
     * explanation before a particular trip, and a later `true` that reset it to today would make
     * that answer quietly wrong.
     */
    expect(UserPreference::sole()->onboarding_seen_at->timestamp)->toBe($firstSeen->timestamp);
});

it('never lets a client mark something back to unseen', function () {
    $signIn = signIn();

    $this->withToken($signIn['session']['accessToken'])
        ->patchJson('/api/v1/account/preferences', ['onboardingSeen' => true])->assertOk();

    $this->withToken($signIn['session']['accessToken'])
        ->patchJson('/api/v1/account/preferences', ['onboardingSeen' => false])
        ->assertOk()->assertJsonPath('data.onboardingSeen', true);

    expect(UserPreference::sole()->onboarding_seen_at)->not->toBeNull();
});

/**
 * A withdrawn capability is unreachable from the API today — there is no withdraw endpoint — but
 * the row can exist, and `insertOrIgnore` leaves existing rows alone. This pins that re-enrolling
 * does NOT silently reactivate it, which is the behaviour the code documents rather than a
 * reactivation policy nobody has specified.
 */
it('does not quietly reactivate a capability somebody withdrew', function () {
    $signIn = signIn();
    completeProfileWithoutRole($signIn['session']['accessToken'])->assertOk();
    chooseRole($signIn['session']['accessToken'], 'driver')->assertOk();

    $capability = UserCapability::sole();
    $capability->status = CapabilityStatus::Withdrawn;
    $capability->withdrawn_at = now();
    $capability->save();

    $response = chooseRole($signIn['session']['accessToken'], 'driver')->assertOk();

    expect(UserCapability::count())->toBe(1)
        ->and(UserCapability::sole()->status)->toBe(CapabilityStatus::Withdrawn)
        ->and($response->json('data.user.capabilities'))->toBe([]);
});
