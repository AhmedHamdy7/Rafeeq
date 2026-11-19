<?php

namespace App\Domains\Shared\Testing;

use App\Domains\Shared\Support\TestAccounts;
use Illuminate\Support\Facades\Cache;

final class HiddenInstapay
{
    public static function hide(string $userId): void
    {
        Cache::put(self::key($userId), true, now()->addDays(7));
    }

    public static function show(string $userId): void
    {
        Cache::forget(self::key($userId));
    }

    public static function isHiddenFor(?string $userId): bool
    {
        return $userId !== null && TestAccounts::apiEnabled() && Cache::get(self::key($userId)) === true;
    }

    private static function key(string $userId): string
    {
        return "testing:instapay:hidden:{$userId}";
    }
}
