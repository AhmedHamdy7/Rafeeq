<?php

use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Models\User;
use Illuminate\Database\QueryException;

/**
 * Chapter 2 §17 and scenarios A/J.
 */
function completeProfile(string $accessToken, array $overrides = [])
{
    return test()->withToken($accessToken)->putJson('/api/v1/account/profile/basic', array_merge([
        'fullName' => 'مريم حسن',
        'gender' => 'woman',
        'registeredRole' => 'passenger',
        'dateOfBirth' => '1996-04-12',
        'preferredLanguage' => 'ar',
    ], $overrides));
}

it('completes the profile and opens the way Home', function () {
    $signIn = signIn();

    test()->withToken($signIn['session']['accessToken'])
        ->patchJson('/api/v1/account/devices/current/security', ['hasLocalPin' => true])->assertOk();

    $response = completeProfile($signIn['session']['accessToken'])->assertOk();

    expect($response->json('data.nextStep'))->toBe('LOCAL_SECURITY_SETUP_OR_HOME')
        ->and($response->json('data.user.profileStatus'))->toBe('BASIC_COMPLETE')
        // Derived, not asked for: what other members see.
        ->and($response->json('data.user.publicFirstName'))->toBe('مريم')
        ->and(User::sole()->profile_status)->toBe(ProfileStatus::BasicComplete);
});

it('stores gender without ever rendering it back', function () {
    $signIn = signIn();

    $response = completeProfile($signIn['session']['accessToken'], ['gender' => 'woman'])->assertOk();

    expect($response->json('data.user'))->not->toHaveKey('gender')
        ->and($response->getContent())->not->toContain('woman')
        // It IS stored — it has to be, it drives the women-only filter.
        ->and(User::sole()->gender->value)->toBe('woman');
});

it('accepts Arabic and Latin names alike, and rejects a digits-only one', function (string $name, bool $valid) {
    $signIn = signIn();

    completeProfile($signIn['session']['accessToken'], ['fullName' => $name])
        ->assertStatus($valid ? 200 : 422);
})->with([
    ['مريم حسن عبد الله', true],
    ['Mariam Hassan', true],
    ["Sara O'Brien-Smith", true],
    ['12345678', false],
    ['م', false],
]);

it('refuses anyone below the minimum age', function () {
    $signIn = signIn();

    $tooYoung = now()->subYears((int) config('rafeeq.profile.minimum_age_years') - 1)->toDateString();

    completeProfile($signIn['session']['accessToken'], ['dateOfBirth' => $tooYoung])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['dateOfBirth']]]);

    expect(User::sole()->profile_status)->toBe(ProfileStatus::NotStarted);
});

/**
 * Scenario J — connectivity lost mid-setup, resumed on the next launch.
 */
it('can be submitted again without conflict', function () {
    $signIn = signIn();

    completeProfile($signIn['session']['accessToken'])->assertOk();
    completeProfile($signIn['session']['accessToken'], ['fullName' => 'مريم حسن علي'])->assertOk();

    expect(User::count())->toBe(1)
        ->and(User::sole()->full_name)->toBe('مريم حسن علي');
});

it('does not mark the server profile complete when the request is rejected', function () {
    $signIn = signIn();

    completeProfile($signIn['session']['accessToken'], ['gender' => 'not-a-gender'])->assertStatus(422);

    expect(User::sole()->profile_status)->toBe(ProfileStatus::NotStarted)
        ->and(User::sole()->full_name)->toBeNull();
});

/**
 * Scenario H — a suspended account is blocked from profile-sensitive actions
 * while keeping a perfectly valid session.
 */
it('blocks a suspended account from editing its profile but not from seeing its status', function () {
    $signIn = signIn();

    User::sole()->forceFill(['account_status' => AccountStatus::Suspended->value])->save();

    completeProfile($signIn['session']['accessToken'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');

    $this->withToken($signIn['session']['accessToken'])->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.nextStep', 'ACCOUNT_SUSPENDED');

    $this->withToken($signIn['session']['accessToken'])->postJson('/api/v1/auth/logout')->assertOk();
});

/**
 * The guarantee behind making `gender` and `registered_role` nullable: the
 * database itself refuses to call a profile complete while they are unknown.
 */
it('cannot be marked complete in the database while identity fields are missing', function () {
    signIn();

    expect(fn () => User::sole()->forceFill([
        'profile_status' => ProfileStatus::BasicComplete->value,
    ])->save())->toThrow(QueryException::class);
});
