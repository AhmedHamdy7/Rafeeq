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

    public static function isTestPhone(?string $e164): bool
    {
        if ($e164 === null || $e164 === '') {
            return false;
        }

        foreach ((array) config('rafeeq.testing.phone_prefixes', []) as $prefix) {
            if ($prefix !== '' && str_starts_with($e164, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function isTestAccount(User|string|null $user): bool
    {
        if (is_string($user)) {
            $user = User::query()->find($user);
        }

        return $user !== null && self::isTestPhone($user->phone_e164);
    }
}
