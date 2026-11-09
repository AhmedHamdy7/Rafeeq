<?php

namespace App\Domains\Admin\Support;

final class AdminMfa
{
    public static function enabled(): bool
    {
        return app()->isProduction() || config('rafeeq.admin_mfa_enabled') !== false;
    }
}
