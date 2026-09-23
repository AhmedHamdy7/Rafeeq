<?php

namespace App\Http\Resources;

use App\Domains\Identity\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A device on the "your devices" screen (Chapter 2 §21).
 *
 * `push_token` is never rendered: it is an encrypted column and a live
 * credential for reaching the person's phone. `has_local_pin` is a fact
 * about the installation, never the PIN itself — the server has never seen
 * one and this is the only trace it keeps.
 *
 * @mixin Device
 */
final class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform->value,
            'model' => $this->device_model,
            'osVersion' => $this->os_version,
            'appVersion' => $this->app_version,
            'isTrusted' => $this->is_trusted,
            'hasLocalPin' => $this->has_local_pin,
            'biometricEnabled' => $this->biometric_enabled,
            'lastSeenAt' => $this->last_seen_at?->toIso8601String(),
            'revokedAt' => $this->revoked_at?->toIso8601String(),
            // Lets the app grey out "sign out this device" for the phone the
            // person is holding, instead of guessing by model name.
            'isCurrent' => $this->when(
                $request->attributes->has('current_device_id'),
                fn () => $request->attributes->get('current_device_id') === $this->id,
            ),
        ];
    }
}
