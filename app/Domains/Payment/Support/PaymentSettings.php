<?php

namespace App\Domains\Payment\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * The money numbers staff may change at runtime. `platform_settings` wins, `config/rafeeq.php`
 * holds the shipped default (standard #11).
 */
final class PaymentSettings
{
    public static function collectionDelayMinutes(): int
    {
        return max(0, (int) self::get('payment.collection_delay_minutes'));
    }

    public static function maxDriverDebtPiastres(): int
    {
        return max(0, (int) self::get('payment.max_driver_debt_piastres'));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
