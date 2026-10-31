<?php

namespace App\Domains\Notification\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * The numbers behind the platform's own messages (Feature 03 files §8).
 *
 * Same contract as the other settings readers: `platform_settings` wins, `config/rafeeq.php` holds
 * the shipped default (standard #11).
 */
final class NotificationSettings
{
    /** How many minutes before departure the "about an hour" reminder goes out. */
    public static function tripReminderEarlyMinutes(): int
    {
        return max(1, (int) self::get('notifications.trip_reminder_early_minutes'));
    }

    /** How many minutes before departure the final reminder goes out. */
    public static function tripReminderFinalMinutes(): int
    {
        return max(1, (int) self::get('notifications.trip_reminder_final_minutes'));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
