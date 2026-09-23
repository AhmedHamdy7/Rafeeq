<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\DeviceIdentity;

/**
 * Finds or creates the `devices` row for this installation.
 *
 * Keyed on (user, device_public_id), matching the composite unique index:
 * one physical phone may legitimately hold several accounts ("use another
 * account"), and each of those installations keeps its own PIN state and
 * its own trust.
 */
final readonly class RegisterDeviceAction
{
    public function execute(User $user, DeviceIdentity $identity): Device
    {
        $device = Device::firstOrNew([
            'user_id' => $user->id,
            'device_public_id' => $identity->publicId,
        ]);

        $device->fill(array_filter([
            'platform' => $identity->platform->value,
            'device_model' => $identity->model,
            'os_version' => $identity->osVersion,
            'app_version' => $identity->appVersion,
            'push_token' => $identity->pushToken,
        ], fn ($value) => $value !== null));

        // A reinstall generates a new `device_public_id`, so it lands here as
        // a brand-new row with `has_local_pin` false — which is exactly why
        // scenario B asks for a PIN again without any special-casing.
        $device->last_seen_at = now();

        $device->save();

        return $device;
    }
}
