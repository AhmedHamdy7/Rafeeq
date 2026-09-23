<?php

namespace App\Domains\Identity\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * Profile-policy numbers, tunable at runtime (binding standard #11).
 *
 * Same contract as {@see AuthSettings}: `platform_settings` wins, and the
 * value in `config/rafeeq.php` is only the shipped default. These are policy
 * decisions that will change without a code change — the minimum age
 * especially, which is still awaiting product/legal sign-off — so the admin
 * dashboard must be able to move them.
 */
final class ProfileSettings
{
    public static function minimumAgeYears(): int
    {
        return (int) self::get('profile.minimum_age_years');
    }

    public static function fullNameMinLength(): int
    {
        return (int) self::get('profile.full_name_min_length');
    }

    public static function fullNameMaxLength(): int
    {
        return (int) self::get('profile.full_name_max_length');
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
