<?php

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\EmergencyContact;
use App\Domains\Verification\Models\TrustScore;

/**
 * The privacy promises the product makes out loud (Bible §8.2's mandatory
 * test list, pitfalls #21/#30): a number is "hidden from other members —
 * always", full names "stay private within the group", gender is a hard
 * filter that must never appear in output, and the internal trust score is
 * never exposed.
 *
 * These assert the safe DEFAULT. A Resource that deliberately shows someone
 * their own data opts in with ->makeVisible(); nothing leaks by accident.
 */
it('never serializes personal data by default', function (string $attribute) {
    $user = User::factory()->create();

    expect($user->toArray())->not->toHaveKey($attribute);
})->with(['phone_e164', 'full_name', 'gender', 'email', 'date_of_birth']);

it('never leaks the raw phone number anywhere in the JSON payload', function () {
    $user = User::factory()->create();

    expect($user->toJson())->not->toContain($user->getRawOriginal('phone_e164'));
});

it('still exposes the public first name, which is what members are meant to see', function () {
    $user = User::factory()->create();

    expect($user->toArray())->toHaveKey('public_first_name');
});

it('allows an explicit opt-in for a self-profile view', function () {
    $user = User::factory()->create();

    $ownProfile = $user->makeVisible(['phone_e164', 'full_name'])->toArray();

    expect($ownProfile)->toHaveKey('phone_e164')
        ->and($ownProfile)->toHaveKey('full_name')
        ->and($ownProfile)->not->toHaveKey('gender'); // still absolute
});

it('never serializes an emergency contact phone number by default', function () {
    $contact = EmergencyContact::factory()->create();

    expect($contact->toArray())->not->toHaveKey('phone_e164');
});

it('never serializes the internal trust score', function () {
    $trustScore = TrustScore::factory()->create();

    expect($trustScore->toArray())->not->toHaveKey('score')
        ->and($trustScore->toArray())->toHaveKey('public_tier');
});
