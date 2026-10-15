<?php

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\ComfortPreference;
use App\Domains\Identity\Models\UserComfortPreference;

/**
 * Feature 02 v3 §178: "Comfort & atmosphere: one entry but **different content by active mode**.
 * Passenger: desired environment; Driver: conditions offered/allowed. Illustrative fields: smoking,
 * music, conversation, AC. **Avoid claiming these are strict filters until matching rules are
 * defined.**"
 */
function comfort(string $token)
{
    return test()->withToken($token)->getJson('/api/v1/account/comfort-preferences');
}

function setComfort(string $token, array $payload)
{
    return test()->withToken($token)->patchJson('/api/v1/account/comfort-preferences', $payload);
}

it('answers with no preference on every question before anybody has chosen', function () {
    $token = verifiedPassenger('01099887766');

    $data = comfort($token)->assertOk()->json('data');

    expect($data['passenger'])->toMatchArray([
        'mode' => 'passenger',
        'smoking' => 'either',
        'music' => 'either',
        'conversation' => 'either',
        'airConditioning' => 'either',
    ])->and($data['driver']['mode'])->toBe('driver');

    /*
     * 🔴 And reading wrote nothing. Somebody who has never opened the screen should not have a row
     * recording that she looked at it.
     */
    expect(UserComfortPreference::count())->toBe(0);
});

it('keeps the two modes apart, because the same answer means opposite things', function () {
    $token = verifiedPassenger('01099887766');

    // As a passenger: "I would rather nobody smoked."
    setComfort($token, ['mode' => 'passenger', 'smoking' => 'no'])->assertOk();

    // As a driver: "smoking is fine in my car."
    setComfort($token, ['mode' => 'driver', 'smoking' => 'yes'])->assertOk();

    $data = comfort($token)->assertOk()->json('data');

    expect($data['passenger']['smoking'])->toBe('no')
        ->and($data['driver']['smoking'])->toBe('yes')
        ->and(UserComfortPreference::count())->toBe(2);
});

it('changes only what was sent', function () {
    $token = verifiedPassenger('01099887766');

    setComfort($token, [
        'mode' => 'passenger',
        'smoking' => 'no',
        'music' => 'yes',
        'conversation' => 'no',
        'airConditioning' => 'yes',
    ])->assertOk();

    setComfort($token, ['mode' => 'passenger', 'music' => 'either'])->assertOk();

    expect(comfort($token)->json('data.passenger'))->toMatchArray([
        'smoking' => 'no',
        'music' => 'either',
        'conversation' => 'no',
        'airConditioning' => 'yes',
    ]);
});

/**
 * The PATCH is sent from a phone, so the same one arriving twice must not make a second row — the
 * unique index on `(user_id, mode)` is what makes that true rather than the Action being careful.
 */
it('survives the same change arriving twice', function () {
    $token = verifiedPassenger('01099887766');

    setComfort($token, ['mode' => 'passenger', 'smoking' => 'no'])->assertOk();
    setComfort($token, ['mode' => 'passenger', 'smoking' => 'no'])->assertOk();

    expect(UserComfortPreference::where('mode', Capability::Passenger->value)->count())->toBe(1);
});

it('refuses an answer it does not recognise, and a missing mode', function () {
    $token = verifiedPassenger('01099887766');

    setComfort($token, ['mode' => 'passenger', 'smoking' => 'sometimes'])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    setComfort($token, ['smoking' => 'no'])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    expect(UserComfortPreference::count())->toBe(0);
});

/**
 * 🔒 The restraint v3 asks for, asserted so that wiring these into matching has to be a deliberate
 * act that breaks a test rather than a quiet afternoon's "improvement".
 *
 * A passenger who asked for a quiet car and got a chatty driver is mildly disappointed. A passenger
 * silently excluded from every commute because she ticked a box has an empty search result she
 * cannot explain and no way to find out why.
 */
it('does not let a comfort answer change what a search returns', function () {
    $driverToken = approvedDriver();
    $vehicleId = test()->withToken($driverToken)->getJson('/api/v1/driver/vehicles')->json('data.0.id');
    $commuteId = readyCommute($driverToken, $vehicleId);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    // The driver's car allows smoking; the passenger does not want it. Opposite answers.
    setComfort($driverToken, ['mode' => 'driver', 'smoking' => 'yes'])->assertOk();

    $paxToken = verifiedPassenger('01099887766');
    $before = count(search($paxToken)->assertOk()->json('data'));

    setComfort($paxToken, ['mode' => 'passenger', 'smoking' => 'no'])->assertOk();

    expect(count(search($paxToken)->assertOk()->json('data')))->toBe($before)
        ->and($before)->toBeGreaterThan(0);
});

it('stores a driver`s answers without her needing a passenger row', function () {
    $driverToken = approvedDriver();

    setComfort($driverToken, ['mode' => 'driver', 'conversation' => 'no'])->assertOk();

    $rows = UserComfortPreference::all();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->mode)->toBe(Capability::Driver)
        ->and($rows->first()->conversation)->toBe(ComfortPreference::No)
        // Untouched questions keep the default rather than becoming null.
        ->and($rows->first()->music)->toBe(ComfortPreference::Either);
});
