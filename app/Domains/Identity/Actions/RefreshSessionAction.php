<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Enums\SessionRevocationReason;
use App\Domains\Identity\Models\AuthSession;
use App\Domains\Identity\Support\IssuedSession;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Rotating refresh with reuse detection (Chapter 2 §23.3).
 *
 * Every refresh burns the presented token and issues a new one in the same
 * token family. A refresh token is therefore single-use by construction —
 * so a SECOND use of one that was already rotated can only mean it was
 * captured. At that point there is no way to tell the thief from the
 * rightful holder, so the entire family is revoked and both are forced to
 * re-authenticate with an OTP (RFC 9700 §4.14.2, and the chapter's own
 * "revoke suspicious token family").
 */
final readonly class RefreshSessionAction
{
    public function __construct(private IssueSessionAction $issueSession) {}

    public function execute(string $refreshToken): IssuedSession
    {
        $hash = IssueSessionAction::hashRefreshToken($refreshToken);

        // Indexed equality on the hash — the plaintext token never touches
        // the database, and an attacker with a read of `auth_sessions` gets
        // nothing they can present.
        $session = AuthSession::query()
            ->with(['user', 'device'])
            ->where('refresh_token_hash', $hash)
            ->first();

        if ($session === null) {
            throw DomainException::of(ErrorCode::SessionInvalid);
        }

        if ($session->isRevoked()) {
            return $this->handleRevokedToken($session);
        }

        if ($session->isRefreshExpired()) {
            $session->revoke(SessionRevocationReason::Superseded);

            throw DomainException::of(ErrorCode::SessionExpired);
        }

        if ($session->device->isRevoked()) {
            // Scenario G: revoking a device from elsewhere has to survive
            // the thief simply calling refresh.
            $session->revoke(SessionRevocationReason::StolenDevice);

            throw DomainException::of(ErrorCode::DeviceRevoked);
        }

        return DB::transaction(function () use ($session): IssuedSession {
            $issued = $this->issueSession->execute(
                user: $session->user,
                device: $session->device,
                // Same family: rotation is a continuation of one sign-in, and
                // this is the thread a later revocation pulls.
                tokenFamilyId: $session->token_family_id,
                previousSession: $session,
            );

            $session->forceFill(['last_refreshed_at' => now()])->save();
            $session->revoke(SessionRevocationReason::Rotated);

            SecurityLog::record(SecurityEventType::SessionRefreshed, $session->user, $session->device);

            return $issued;
        });
    }

    private function handleRevokedToken(AuthSession $session): never
    {
        $reason = $session->revocation_reason;

        if ($reason === null || ! $reason->indicatesTheft()) {
            // A logged-out or superseded token is just a stale client. Nuking
            // every other device over it would turn an ordinary race into a
            // mass sign-out. A device that was deliberately revoked gets its
            // own answer, so the app can say why rather than showing a
            // generic "please sign in again".
            throw DomainException::of(
                $reason === SessionRevocationReason::StolenDevice
                    ? ErrorCode::DeviceRevoked
                    : ErrorCode::SessionInvalid,
            );
        }

        $this->revokeFamily($session);

        SecurityLog::record(SecurityEventType::TokenReuse, $session->user, $session->device, [
            'token_family_id' => $session->token_family_id,
            'reused_session_id' => $session->id,
        ]);

        throw DomainException::of(ErrorCode::SessionReuseDetected);
    }

    /**
     * The whole chain descended from the compromised sign-in — including the
     * currently-live token the thief may be holding.
     */
    private function revokeFamily(AuthSession $session): void
    {
        AuthSession::query()
            ->where('token_family_id', $session->token_family_id)
            ->get()
            ->each(fn (AuthSession $member) => $member->revoke(SessionRevocationReason::TokenReuse));
    }
}
