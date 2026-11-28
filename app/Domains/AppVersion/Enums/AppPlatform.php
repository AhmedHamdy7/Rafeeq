<?php

namespace App\Domains\AppVersion\Enums;

/** The two stores the mobile app ships through. Same values as `devices.platform`. */
enum AppPlatform: string
{
    case Ios = 'ios';
    case Android = 'android';
}
