<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Enums\SessionRevocationReason;
use App\Domains\Identity\Models\AuthSession;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Ending sessions — the current one (Chapter 2 §23.4) or another device's
 * (§23.5).
 *
 * Both go through `AuthSession::revoke()`, which kills the refresh row AND
 * deletes the matching Sanctum access token. Revoking only the refresh row
 * would leave a working access token in the thief's hands for the rest of
 * its lifetime, which is exactly what scenario G is about.
 */
final readonly class RevokeSessionAction
{
    public function logout(AuthSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $session->revoke(SessionRevocationReason::UserLogout);

            // §23.4: drop the push-token association. The account survives;
            // only this installation's ability to reach the person does.
            $session->device->forceFill(['push_token' => null])->save();

            SecurityLog::record(
                SecurityEventType::Logout,
                $session->user,
                $session->device,
            );
        });
    }

    /**
     * §23.5: sign a DIFFERENT device out. The caller's own session is left
     * alone unless they picked it, so revoking a stolen phone from a laptop
     * does not lock the person out of the device they are holding.
     */
    public function revokeDevice(User $user, Device $device, SessionRevocationReason $reason): void
    {
        DB::transaction(function () use ($user, $device, $reason): void {
            AuthSession::query()
                ->where('device_id', $device->id)
                ->whereNull('revoked_at')
                ->get()
                ->each(fn (AuthSession $session) => $session->revoke($reason));

            $device->forceFill([
                'revoked_at' => now(),
                'is_trusted' => false,
                'push_token' => null,
            ])->save();

            SecurityLog::record(
                SecurityEventType::DeviceRevoked,
                $user,
                $device,
                ['reason' => $reason->value],
            );
        });
    }
}
