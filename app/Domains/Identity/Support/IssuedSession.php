<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Models\AuthSession;
use Carbon\CarbonImmutable;

/**
 * What a successful sign-in or refresh hands back. The two plaintext tokens
 * exist ONLY inside this object on the way to the response — neither is
 * stored anywhere on the server (the access token lives as a Sanctum hash,
 * the refresh token as a sha256), so this is the one and only moment the
 * caller can read them.
 */
final readonly class IssuedSession
{
    public function __construct(
        public AuthSession $session,
        public string $accessToken,
        public string $refreshToken,
        public CarbonImmutable $accessExpiresAt,
        public CarbonImmutable $refreshExpiresAt,
    ) {}
}
