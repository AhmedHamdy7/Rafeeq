<?php

namespace App\Http\Resources;

use App\Domains\Identity\Support\IssuedSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The token pair (Chapter 2 §23.2/§23.3).
 *
 * The expiry timestamps are sent so the app can refresh proactively instead
 * of discovering expiry through a failed request mid-journey. Nothing about
 * the session row itself is exposed — `refresh_token_hash` and the family id
 * are server-side bookkeeping.
 *
 * @mixin IssuedSession
 */
final class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'accessExpiresAt' => $this->accessExpiresAt->toIso8601String(),
            'refreshExpiresAt' => $this->refreshExpiresAt->toIso8601String(),
        ];
    }
}
