<?php

namespace App\Domains\Notification\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * Trip-chat numbers, tunable at runtime (standard #11). See `config/rafeeq.php` → `chat`.
 */
final class ChatSettings
{
    public static function opensHoursBefore(): int
    {
        return max(1, (int) self::get('chat.opens_hours_before'));
    }

    public static function graceMinutes(): int
    {
        return max(0, (int) self::get('chat.grace_minutes'));
    }

    public static function maxHoursAfterDeparture(): int
    {
        return max(1, (int) self::get('chat.max_hours_after_departure'));
    }

    public static function messagesPerMinute(): int
    {
        return max(1, (int) self::get('chat.messages_per_minute'));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
