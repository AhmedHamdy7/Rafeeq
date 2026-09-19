<?php

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

it('encrypts national_id and licence_number while keeping a searchable hash', function () {
    $profile = DriverProfile::factory()->create();

    $raw = DB::table('driver_profiles')
        ->where('user_id', $profile->user_id)
        ->first();

    expect($raw->national_id_encrypted)->not->toBeNull()
        ->and($raw->national_id_hash)->toBe(hash('sha256', $profile->national_id))
        ->and($raw->licence_number_hash)->toBe(hash('sha256', $profile->licence_number));
});

it('hides encrypted and hash columns from serialization', function () {
    $profile = DriverProfile::factory()->create();

    expect($profile->toArray())
        ->not->toHaveKey('national_id_encrypted')
        ->not->toHaveKey('national_id_hash')
        ->not->toHaveKey('licence_number_encrypted')
        ->not->toHaveKey('licence_number_hash');
});

it('finds a duplicate national ID by hash without decrypting', function () {
    $nationalId = '29604011234567';
    DriverProfile::factory()->create(['national_id' => $nationalId]);

    $found = DriverProfile::query()->where('national_id_hash', hash('sha256', $nationalId))->first();

    expect($found)->not->toBeNull();
});

it('reports licence validity from licence_expiry', function () {
    $valid = DriverProfile::factory()->create();
    $expired = DriverProfile::factory()->withExpiredLicence()->create();

    expect($valid->hasValidLicence())->toBeTrue()
        ->and($expired->hasValidLicence())->toBeFalse();
});

it('uses user_id as its own primary key, one profile per user', function () {
    $user = User::factory()->create();
    $profile = DriverProfile::factory()->create(['user_id' => $user->id]);

    expect($profile->getKey())->toBe($user->id);
});
