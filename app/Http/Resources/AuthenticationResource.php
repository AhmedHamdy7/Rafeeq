<?php

namespace App\Http\Resources;

use App\Domains\Identity\Support\AuthenticationResult;
use App\Domains\Identity\Support\AuthSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The response to a verified OTP (Chapter 2 §23.2).
 *
 * Identical in shape for a brand-new account and a returning one — the only
 * difference is the value of `accountState`, which the caller has by then
 * earned the right to know by proving control of the phone.
 *
 * @mixin AuthenticationResult
 */
final class AuthenticationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'accountState' => $this->accountState->value,
            'nextStep' => $this->nextStep->value,
            // The app needs the length to render the right number of boxes;
            // sending it avoids a second source of truth in the client.
            'pinLength' => AuthSettings::pinLength(),
            'session' => new SessionResource($this->session),
            'user' => new UserResource($this->user),
            'device' => new DeviceResource($this->device),
        ];
    }
}
