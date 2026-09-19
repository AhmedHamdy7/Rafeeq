<?php

use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPlace;
use App\Domains\Identity\Models\UserStat;
use Illuminate\Database\QueryException;

it('creates a user with a ULID primary key', function () {
    $user = User::factory()->create();

    expect($user->id)->toBeString()->and(strlen($user->id))->toBe(26);
});

it('never includes gender in array or JSON output', function () {
    $user = User::factory()->create();

    expect($user->toArray())->not->toHaveKey('gender')
        ->and($user->toJson())->not->toContain('gender');

    // The raw attribute is still reachable in PHP code — hidden ≠ removed.
    expect($user->gender)->not->toBeNull();
});

it('rejects a second active user with the same phone number', function () {
    $user = User::factory()->create(['phone_e164' => '+201012345678']);

    User::factory()->create(['phone_e164' => '+201012345678']);
})->throws(QueryException::class);

it('allows a phone number to be re-registered after the original account is soft-deleted', function () {
    $original = User::factory()->create(['phone_e164' => '+201012345678']);
    $original->delete();

    $reregistered = User::factory()->create(['phone_e164' => '+201012345678']);

    expect($reregistered->exists)->toBeTrue()
        ->and(User::withTrashed()->where('phone_e164', '+201012345678')->count())->toBe(2);
});

it('allows two different soft-deleted accounts to have shared the same original phone number', function () {
    $first = User::factory()->create(['phone_e164' => '+201099999999']);
    $first->delete();

    $second = User::factory()->create(['phone_e164' => '+201099999999']);
    $second->delete();

    expect(User::onlyTrashed()->where('phone_e164', '+201099999999')->count())->toBe(2);
});

it('reports active status via the account_status enum', function () {
    $active = User::factory()->create(['account_status' => AccountStatus::Active]);
    $suspended = User::factory()->create(['account_status' => AccountStatus::Suspended]);

    expect($active->isActive())->toBeTrue()
        ->and($suspended->isActive())->toBeFalse();
});

it('loads its organization, devices, stats and places relations', function () {
    $user = User::factory()
        ->has(Device::factory()->count(2), 'devices')
        ->has(UserStat::factory(), 'stats')
        ->has(UserPlace::factory(), 'places')
        ->create();

    expect($user->devices)->toHaveCount(2)
        ->and($user->stats)->not->toBeNull()
        ->and($user->places)->toHaveCount(1);
});
