<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\SessionRevocationReason;
use App\Domains\Identity\Models\AuthSession;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\AuthSettings;
use App\Domains\Identity\Support\IssuedSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mints the token pair for a device (Chapter 2 §23.2/§23.3).
 *
 * Two different lifetimes doing two different jobs:
 *  - the access token is a Sanctum token, short-lived, named after the
 *    session so revoking the session can actually kill it;
 *  - the refresh token is an opaque random secret of which the server keeps
 *    only a sha256, and which is replaced on every use.
 *
 * Every session carries a `token_family_id`. A fresh sign-in starts a new
 * family; a refresh stays in the same one. That single column is what makes
 * "revoke everything descended from the stolen token" a one-line update in
 * RefreshSessionAction instead of a walk up a linked list.
 */
final readonly class IssueSessionAction
{
    public function execute(
        User $user,
        Device $device,
        ?string $tokenFamilyId = null,
        ?AuthSession $previousSession = null,
    ): IssuedSession {
        $accessExpiresAt = CarbonImmutable::now()->addMinutes(AuthSettings::accessTtlMinutes());
        $refreshExpiresAt = CarbonImmutable::now()->addDays(AuthSettings::refreshTtlDays());

        // 64 raw bytes from the CSPRNG. Long enough that guessing is not a
        // threat model, so the stored sha256 needs no slow hash — unlike a
        // password, there is no low-entropy secret to protect.
        $refreshToken = Str::random(64);

        return DB::transaction(function () use (
            $user, $device, $tokenFamilyId, $previousSession,
            $accessExpiresAt, $refreshExpiresAt, $refreshToken
        ): IssuedSession {
            // A new sign-in retires whatever this device was holding, so a
            // later logout cannot leave an older refresh token alive.
            if ($previousSession === null) {
                $this->retirePreviousSessionsOn($device);
            }

            $session = AuthSession::create([
                'user_id' => $user->id,
                'device_id' => $device->id,
                'refresh_token_hash' => self::hashRefreshToken($refreshToken),
                'token_family_id' => $tokenFamilyId ?? (string) Str::ulid(),
                'previous_session_id' => $previousSession?->id,
                'access_expires_at' => $accessExpiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
            ]);

            $device->forceFill(['last_seen_at' => now(), 'revoked_at' => null])->save();

            // The token's name is the session id: that is the join that lets
            // logout and device revocation delete the right access token
            // without another column.
            $accessToken = $user->createToken($session->id, ['*'], $accessExpiresAt);

            return new IssuedSession(
                session: $session,
                accessToken: $accessToken->plainTextToken,
                refreshToken: $refreshToken,
                accessExpiresAt: $accessExpiresAt,
                refreshExpiresAt: $refreshExpiresAt,
            );
        });
    }

    /**
     * sha256, not bcrypt: the lookup is "find the session holding this
     * token", which has to be an indexed equality match. A salted hash
     * would force a table scan and a verify per row.
     */
    public static function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function retirePreviousSessionsOn(Device $device): void
    {
        AuthSession::query()
            ->where('device_id', $device->id)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (AuthSession $stale) => $stale->revoke(SessionRevocationReason::Superseded));
    }
}
