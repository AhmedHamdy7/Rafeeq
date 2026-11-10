<?php

namespace App\Domains\Shared\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;

final class TestAccounts
{
    /**
     * @return list<string>
     */
    public static function patterns(): array
    {
        return array_values(array_filter((array) config('rafeeq.testing.phones', []), fn ($pattern): bool => is_string($pattern) && $pattern !== ''));
    }

    public static function isTestPhone(string $e164): bool
    {
        foreach (self::patterns() as $pattern) {
            if (fnmatch($pattern, $e164)) {
                return true;
            }
        }

        return false;
    }

    public static function fixedCodeAppliesTo(string $e164): bool
    {
        return self::patterns() === [] || self::isTestPhone($e164);
    }

    public static function apiEnabled(): bool
    {
        return ! app()->isProduction() && (bool) config('rafeeq.testing.api');
    }

    public static function assertAllowed(?User $user): User
    {
        if ($user === null || ! self::apiEnabled() || ! self::isTestPhone((string) $user->phone_e164)) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return $user;
    }
}
