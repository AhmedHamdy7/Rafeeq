<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Models\AuthSession;
use Illuminate\Http\Request;

/**
 * Recovers the `auth_sessions` row behind the access token on this request.
 *
 * The link is the token's `name`, which `IssueSessionAction` sets to the
 * session id — chosen over an extra column so there is exactly one place
 * the two can get out of step, and it is enforced at creation.
 */
final class CurrentSession
{
    public static function for(Request $request): ?AuthSession
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null || ! isset($token->name)) {
            return null;
        }

        return AuthSession::query()
            ->with(['device', 'user'])
            ->whereKey($token->name)
            ->first();
    }
}
