<?php

namespace App\Domains\Verification\Enums;

enum VirusScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
}
