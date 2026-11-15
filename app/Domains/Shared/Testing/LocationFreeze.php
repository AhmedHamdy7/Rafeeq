<?php

namespace App\Domains\Shared\Testing;

use App\Domains\Shared\Support\TestAccounts;
use Illuminate\Support\Facades\Cache;

final class LocationFreeze
{
    public static function hold(string $sessionId, int $seconds = 60): void
    {
        Cache::put(self::key($sessionId), true, $seconds);
    }

    public static function active(string $sessionId): bool
    {
        return TestAccounts::apiEnabled() && Cache::has(self::key($sessionId));
    }

    private static function key(string $sessionId): string
    {
        return "testing:trip:{$sessionId}:location-frozen";
    }
}
