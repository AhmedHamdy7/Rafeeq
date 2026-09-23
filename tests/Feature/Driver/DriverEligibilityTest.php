<?php

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationType;
use Illuminate\Support\Facades\Storage;

/**
 * Chapter 3 §2: six checks before "Become a Driver" lets anyone in, and a
 * failure must "explain exactly why and offer the required next step".
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->token = signIn()['session']['accessToken'];
});

function eligibility(string $token): array
{
    return test()->withToken($token)
        ->getJson('/api/v1/driver/eligibility')
        ->assertOk()
        ->json('data');
}

it('reports every unmet requirement at once, not just the first', function () {
    // Fresh account: no profile, so no date of birth, and no verified identity.
    completeBasicProfile($this->token);
    User::sole()->forceFill(['date_of_birth' => null])->save();

    $reasons = array_column(eligibility($this->token)['blockers'], 'reason');

    // Someone missing two things should learn that once, not discover the
    // second after fixing the first.
    expect($reasons)->toContain('date_of_birth_missing')
        ->and($reasons)->toContain('identity_not_verified')
        ->and(eligibility($this->token)['eligible'])->toBeFalse();
});

it('offers a next step and a readable message for every blocker', function () {
    completeBasicProfile($this->token);

    foreach (eligibility($this->token)['blockers'] as $blocker) {
        expect($blocker['nextStep'])->not->toBeEmpty()
            ->and($blocker['message'])->not->toBeEmpty()
            // A missing translation would surface the key itself.
            ->and($blocker['message'])->not->toStartWith('driver.');
    }
});

it('says a person is eligible once identity and profile are done', function () {
    completeBasicProfile($this->token);
    submitGovernmentId($this->token);
    approveVerification(VerificationType::GovernmentId);

    expect(eligibility($this->token)['eligible'])->toBeTrue()
        ->and(eligibility($this->token)['blockers'])->toBe([]);
});

/**
 * A date of birth is optional for a passenger and required here: a licence has
 * a legal minimum age, so "we don't know" must not read as "old enough".
 */
it('refuses an application when the age is unknown', function () {
    completeBasicProfile($this->token);
    submitGovernmentId($this->token);
    approveVerification(VerificationType::GovernmentId);

    User::sole()->forceFill(['date_of_birth' => null])->save();

    expect(array_column(eligibility($this->token)['blockers'], 'reason'))
        ->toContain('date_of_birth_missing');

    $this->withToken($this->token)->postJson('/api/v1/driver/application')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'DRIVER_NOT_ELIGIBLE');
});

it('refuses someone below the minimum age', function () {
    completeBasicProfile($this->token);
    submitGovernmentId($this->token);
    approveVerification(VerificationType::GovernmentId);

    User::sole()->forceFill([
        'date_of_birth' => now()->subYears((int) config('rafeeq.profile.minimum_age_years') - 2)->toDateString(),
    ])->save();

    expect(array_column(eligibility($this->token)['blockers'], 'reason'))
        ->toContain('below_minimum_age');
});

/**
 * §14: one pending application only. Without this, several could be queued and
 * reviewers would duplicate each other's work.
 */
it('refuses a second application while one is under review', function () {
    $token = readyDriverApplicant();
    completeDriverApplication($token);

    $this->withToken($token)->postJson('/api/v1/driver/application/submit')->assertOk();

    expect(array_column(eligibility($token)['blockers'], 'reason'))
        ->toContain('application_under_review');
});

it('tells an approved driver they already are one', function () {
    $token = readyDriverApplicant();

    DriverProfile::sole()->forceFill(['status' => DriverProfileStatus::Approved->value])->save();

    $blockers = eligibility($token)['blockers'];

    expect(array_column($blockers, 'reason'))->toContain('already_a_driver')
        ->and(collect($blockers)->firstWhere('reason', 'already_a_driver')['nextStep'])
        ->toBe('OPEN_DRIVER_DASHBOARD');
});

it('tells a suspended driver to contact support', function () {
    $token = readyDriverApplicant();

    DriverProfile::sole()->forceFill(['status' => DriverProfileStatus::Suspended->value])->save();

    expect(array_column(eligibility($token)['blockers'], 'reason'))->toContain('driver_suspended');
});

/**
 * The verification gate built in Phase 3, doing its job on its first real
 * route: an unverified identity may not apply to carry passengers.
 */
it('blocks every driver route behind a verified identity', function (string $method, string $uri) {
    completeBasicProfile($this->token);

    $this->withToken($this->token)->json($method, $uri)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED')
        ->assertJsonPath('error.fields.verification', ['government_id']);
})->with([
    ['GET', '/api/v1/driver/application'],
    ['POST', '/api/v1/driver/application'],
    ['PUT', '/api/v1/driver/application/licence'],
    ['POST', '/api/v1/driver/application/submit'],
    ['GET', '/api/v1/driver/vehicles'],
    ['POST', '/api/v1/driver/vehicles'],
]);

/**
 * But not the endpoint whose whole purpose is to explain what is missing —
 * gating that would leave someone with a refusal and no way to understand it.
 */
it('keeps the eligibility endpoint itself reachable without verification', function () {
    completeBasicProfile($this->token);

    $this->withToken($this->token)->getJson('/api/v1/driver/eligibility')->assertOk();
});

it('requires a complete profile before any driver route', function () {
    // No profile yet at all.
    $this->withToken($this->token)->getJson('/api/v1/driver/eligibility')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_PROFILE_INCOMPLETE');
});
