<?php

namespace App\Domains\Identity\Enums;

enum OtpStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Expired = 'expired';
    case Blocked = 'blocked';
}
