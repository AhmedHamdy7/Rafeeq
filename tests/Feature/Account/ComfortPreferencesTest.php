<?php

use App\Domains\Identity\Models\UserPreference;

/**
 * Feature 02 v3 §178: "Comfort & atmosphere: one entry but **different content by active mode**.
 * Passenger: desired environment; Driver: conditions offered/allowed. **Avoid claiming these are
 * strict filters until matching rules are defined.**"
 *
 * 🔴 These store `CommuteRuleKey` values — the SAME six chips the matcher already speaks
 * (`nonsmoking`, `quiet`, `ac`, `nofood`, `luggage`, `front`). An earlier version of this feature
 * invented a parallel vocabulary in its own table, which nothing read; see the migration that
 * removed it. Saving a default is only useful if it can prefill a search, and it can only do that
 * if it uses the same words.
 */
function comfort(string $token)
{
    return test()->withToken($token)->getJson('/api/v1/account/comfort-preferences');
}

function setComfort(string $token, string $mode, array $rules)
{
    return test()->withToken($token)
        ->patchJson('/api/v1/account/comfort-preferences', ['mode' => $mode, 'rules' => $rules]);
}

it('has nothing saved before anybody chooses, and reading writes nothing', function () {
    $token = verifiedPassenger('01099887766');

    $data = comfort($token)->assertOk()->json('data');

    /*
     * A preference ROW already exists — role enrollment creates one to hold `last_active_mode` —
     * so the thing to assert is that the comfort columns are untouched, not that no row exists.
     * Asserting the row count here would be testing the role step by accident.
     */
    expect($data['passenger'])->toBe(['chosen' => false, 'rules' => []])
        ->and($data['driver'])->toBe(['chosen' => false, 'rules' => []])
        ->and(UserPreference::sole()->passenger_comfort_rules)->toBeNull()
        ->and(UserPreference::sole()->driver_comfort_rules)->toBeNull();
});

it('keeps the two modes apart, because the same chip means opposite things', function () {
    $token = verifiedPassenger('01099887766');

    // As a passenger: "I would like a quiet, non-smoking ride."
    setComfort($token, 'passenger', ['quiet', 'nonsmoking'])->assertOk();

    // As a driver: "my car offers air conditioning and takes luggage."
    setComfort($token, 'driver', ['ac', 'luggage'])->assertOk();

    $data = comfort($token)->assertOk()->json('data');

    expect($data['passenger']['rules'])->toBe(['nonsmoking', 'quiet'])
        ->and($data['driver']['rules'])->toBe(['ac', 'luggage']);
});

/**
 * 🔴 `[]` is a real answer and must survive as one. "I want none of these" is a thing somebody
 * chose; `null` is "never asked". Collapsing them would turn somebody who has not answered into
 * somebody who refused every chip.
 */
it('tells an empty choice apart from never having chosen', function () {
    $token = verifiedPassenger('01099887766');

    setComfort($token, 'passenger', [])->assertOk();

    $data = comfort($token)->assertOk()->json('data');

    /*
     * 🔴 The whole point: both have an empty `rules`, and `chosen` is what tells them apart. She
     * answered "none of them" as a passenger; she has never been asked as a driver.
     */
    expect($data['passenger'])->toBe(['chosen' => true, 'rules' => []])
        ->and($data['driver'])->toBe(['chosen' => false, 'rules' => []]);
});

it('replaces the saved list rather than adding to it', function () {
    $token = verifiedPassenger('01099887766');

    setComfort($token, 'passenger', ['quiet', 'nonsmoking', 'ac'])->assertOk();
    setComfort($token, 'passenger', ['front'])->assertOk();

    expect(comfort($token)->json('data.passenger.rules'))->toBe(['front']);
});

it('stores a double-tapped chip once', function () {
    $token = verifiedPassenger('01099887766');

    setComfort($token, 'passenger', ['quiet', 'quiet', 'ac'])->assertOk();

    expect(comfort($token)->json('data.passenger.rules'))->toBe(['ac', 'quiet']);
});

it('refuses a chip it does not recognise, and a missing mode', function () {
    $token = verifiedPassenger('01099887766');

    setComfort($token, 'passenger', ['sunroof'])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    test()->withToken($token)
        ->patchJson('/api/v1/account/comfort-preferences', ['rules' => ['quiet']])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    // Nothing was stored by either refusal.
    expect(UserPreference::sole()->passenger_comfort_rules)->toBeNull()
        ->and(UserPreference::sole()->driver_comfort_rules)->toBeNull();
});

/**
 * 🔒 The restraint v3 asks for, asserted so that turning these into a filter has to be a deliberate
 * act that breaks a test.
 *
 * A passenger who asked for a quiet car and got a chatty driver is mildly disappointed. A passenger
 * silently excluded from every commute because she saved a preference has an empty result she
 * cannot explain and no way to find out why.
 */
it('does not let a saved preference change what a search returns', function () {
    $driverToken = approvedDriver();
    $vehicleId = test()->withToken($driverToken)->getJson('/api/v1/driver/vehicles')->json('data.0.id');
    $commuteId = readyCommute($driverToken, $vehicleId);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $paxToken = verifiedPassenger('01099887766');
    $before = count(search($paxToken)->assertOk()->json('data'));

    // Save chips the commute does not offer.
    setComfort($paxToken, 'passenger', ['nonsmoking', 'quiet', 'nofood'])->assertOk();

    expect(count(search($paxToken)->assertOk()->json('data')))->toBe($before)
        ->and($before)->toBeGreaterThan(0);
});

/**
 * And the reason the saved list speaks the matcher's language: handed to a search as
 * `requiredRules`, it scores rather than excludes — so a commute that offers none of them is still
 * returned, just lower.
 */
it('is in the same vocabulary a search already accepts', function () {
    $driverToken = approvedDriver();
    $vehicleId = test()->withToken($driverToken)->getJson('/api/v1/driver/vehicles')->json('data.0.id');
    $commuteId = readyCommute($driverToken, $vehicleId);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $paxToken = verifiedPassenger('01099887766');
    setComfort($paxToken, 'passenger', ['quiet'])->assertOk();

    $saved = comfort($paxToken)->json('data.passenger.rules');

    // The stored values go straight into a search without translation, and nothing is excluded.
    $results = search($paxToken, ['requiredRules' => $saved])->assertOk()->json('data');

    expect($results)->not->toBeEmpty();
});
