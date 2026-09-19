<?php

use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('allows two different accounts to share the same physical device', function () {
    $devicePublicId = 'device-abc-123';

    $mariam = User::factory()->create();
    $salma = User::factory()->create();

    Device::factory()->for($mariam)->create(['device_public_id' => $devicePublicId]);
    $second = Device::factory()->for($salma)->create(['device_public_id' => $devicePublicId]);

    expect($second->exists)->toBeTrue();
});

it('rejects the same account registering the same device twice', function () {
    $user = User::factory()->create();

    Device::factory()->for($user)->create(['device_public_id' => 'device-abc-123']);
    Device::factory()->for($user)->create(['device_public_id' => 'device-abc-123']);
})->throws(QueryException::class);

it('encrypts the push token at rest', function () {
    $device = Device::factory()->create(['push_token' => 'a-real-fcm-token']);

    $raw = DB::table('devices')->where('id', $device->id)->value('push_token');

    expect($raw)->not->toBe('a-real-fcm-token')
        ->and($device->fresh()->push_token)->toBe('a-real-fcm-token');
});
