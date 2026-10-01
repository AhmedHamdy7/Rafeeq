<?php

namespace App\Http\Resources;

use App\Domains\Safety\Models\LiveShare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A live-share link, to the person who created it.
 *
 * 🔒 The token is NOT here, and it never will be. The plaintext is returned exactly once, by the
 * endpoint that creates the share, and nowhere else — not even to its owner. Re-reading your own
 * share gives you whether it is still live and whether anybody opened it, which is what you
 * actually need; being able to re-read the token would mean a stolen access token could harvest
 * every share link a person ever made.
 *
 * `token_hash` is `#[Hidden]` on the model as well, so forgetting this is not enough to leak it.
 *
 * @mixin LiveShare
 */
final class LiveShareResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tripSessionId' => $this->trip_session_id,

            /*
             * Who it was shared with, when a contact was named. The contact's own details are not
             * repeated here — this is a list of links, and the contacts endpoint is where their
             * details live.
             */
            'sharedWithContactId' => $this->shared_with_contact_id,

            'expiresAt' => $this->expires_at->toIso8601String(),
            'revokedAt' => $this->revoked_at?->toIso8601String(),

            // Stated rather than left to be worked out from two timestamps and the current clock.
            'isActive' => $this->isActive(),

            /*
             * 🔴 Not analytics. This is how the person who shared the link can tell whether their
             * contact actually looked — which, for somebody who shared it because they felt
             * uneasy, is the question they opened the screen to answer.
             */
            'viewCount' => $this->view_count,
            'lastViewedAt' => $this->last_viewed_at?->toIso8601String(),

            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
