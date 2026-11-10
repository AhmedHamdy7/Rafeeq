<?php

namespace App\Domains\Shared\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;

final class TestAccounts
{
    public static function apiEnabled(): bool
    {
        return ! app()->isProduction() && (bool) config('rafeeq.testing.api');
    }

    public static function assertAllowed(?User $user): User
    {
        if ($user === null || ! self::apiEnabled()) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return $user;
    }
}
