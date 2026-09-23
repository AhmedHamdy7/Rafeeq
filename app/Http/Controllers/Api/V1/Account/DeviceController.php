<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Actions\RevokeSessionAction;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Enums\SessionRevocationReason;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Support\CurrentSession;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Account\UpdateDeviceSecurityRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceController extends Controller
{
    /**
     * GET /v1/account/devices — Chapter 2 §21: the person can see, and
     * therefore revoke, everything signed in to their account.
     */
    public function index(Request $request): JsonResponse
    {
        $current = CurrentSession::for($request);

        if ($current !== null) {
            $request->attributes->set('current_device_id', $current->device_id);
        }

        $devices = $request->user()->devices()
            ->orderByDesc('last_seen_at')
            ->get();

        return ApiResponse::success(DeviceResource::collection($devices));
    }

    /**
     * DELETE /v1/account/devices/{device}/session — Chapter 2 §23.5.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function destroySession(Request $request, string $device, RevokeSessionAction $action): JsonResponse
    {
        $target = $this->ownedDevice($request, $device);

        $action->revokeDevice($request->user(), $target, SessionRevocationReason::StolenDevice);

        return ApiResponse::success(['revoked' => true]);
    }

    /**
     * PATCH /v1/account/devices/current/security — the app reporting that a
     * local PIN or biometric unlock was set up, or handing over a refreshed
     * push token.
     */
    #[ApiErrors(ErrorCode::SessionInvalid)]
    public function updateSecurity(UpdateDeviceSecurityRequest $request): JsonResponse
    {
        $session = CurrentSession::for($request);

        if ($session === null) {
            throw DomainException::of(ErrorCode::SessionInvalid);
        }

        $device = $session->device;
        $hadPin = $device->has_local_pin;

        $device->fill($request->safe()->collect()
            ->mapWithKeys(fn ($value, string $key) => [
                match ($key) {
                    'hasLocalPin' => 'has_local_pin',
                    'biometricEnabled' => 'biometric_enabled',
                    'pushToken' => 'push_token',
                } => $value,
            ])->all());

        // Setting a PIN is what makes an installation trusted: it means the
        // person can be asked to prove themselves locally before the app
        // opens, which is the premise the whole "stay signed in" model rests
        // on (Chapter 2 §16).
        if ($device->has_local_pin) {
            $device->is_trusted = true;
        }

        $device->save();

        if ($device->has_local_pin && ! $hadPin) {
            SecurityLog::record(SecurityEventType::LocalPinSet, $request->user(), $device);
        }

        return ApiResponse::success(new DeviceResource($device));
    }

    /**
     * Scoped to the caller's own devices, and answers 404 — not 403 — for
     * anyone else's. A 403 would confirm that the id exists, which is an
     * enumeration oracle for device ids (Bible §6, IDOR).
     */
    private function ownedDevice(Request $request, string $deviceId): Device
    {
        $device = $request->user()->devices()->whereKey($deviceId)->first();

        if ($device === null) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return $device;
    }
}
