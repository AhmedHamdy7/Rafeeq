<?php

namespace App\Domains\Safety\Support;

final class AutoShare
{
    public static function available(): bool
    {
        if (! SafetySettings::autoShareEnabled()) {
            return false;
        }

        return match (self::driver()) {
            'http' => HttpSmsContactMessenger::configured(),
            'log' => ! app()->isProduction(),
            default => false,
        };
    }

    public static function driver(): string
    {
        return (string) config('rafeeq.safety.auto_share_driver');
    }
}
