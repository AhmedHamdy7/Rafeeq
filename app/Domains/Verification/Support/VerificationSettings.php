<?php

namespace App\Domains\Verification\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * The verification numbers staff may change at runtime. Same contract as the other settings
 * readers: `platform_settings` wins, `config/rafeeq.php` holds the shipped default (standard #11).
 */
final class VerificationSettings
{
    /**
     * Days from upload until an identity or vehicle document's file is destroyed.
     */
    public static function documentRetentionDays(): int
    {
        return max(1, (int) self::get('verification.document_retention_days'));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
