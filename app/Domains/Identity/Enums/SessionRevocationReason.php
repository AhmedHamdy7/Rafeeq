<?php

namespace App\Domains\Identity\Enums;

enum SessionRevocationReason: string
{
    case UserLogout = 'user_logout';
    case TokenReuse = 'token_reuse';
    case Admin = 'admin';
    case StolenDevice = 'stolen_device';

    /**
     * Added in Phase 2: signing in again on a device that already has a live
     * session replaces it. Without a reason of its own, that routine event
     * would have to borrow `user_logout` (a lie in the audit trail) or leave
     * the old refresh token alive, so logging out of the new session would
     * silently leave the old one usable.
     */
    case Superseded = 'superseded';

    /**
     * Also Phase 2: the normal end of a session that was refreshed. It has
     * to be distinguishable from a logout, because presenting a ROTATED
     * token is the signature of a stolen one and triggers a family-wide
     * revocation, whereas presenting a logged-out token is just a stale
     * client that should quietly sign in again.
     */
    case Rotated = 'rotated';

    /**
     * Whether re-presenting a token revoked for this reason means someone
     * captured it. Rotation is single-use by construction, so a second use
     * is either the legitimate holder replaying or an attacker — and there
     * is no way to tell which, hence revoke everything (RFC 9700 §4.14.2).
     */
    public function indicatesTheft(): bool
    {
        return $this === self::Rotated || $this === self::TokenReuse;
    }
}
