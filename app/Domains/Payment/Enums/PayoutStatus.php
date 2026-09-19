<?php

namespace App\Domains\Payment\Enums;

enum PayoutStatus: string
{
    case Pending = 'pending';
    case Cleared = 'cleared';
    case OnHold = 'on_hold';
    case Blocked = 'blocked';
    case Released = 'released';
}
