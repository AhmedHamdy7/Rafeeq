<?php

namespace App\Domains\Trip\Enums;

enum WaitTimerOutcome: string
{
    case Arrived = 'arrived';
    case NoShow = 'no_show';
    case DriverLeft = 'driver_left';
}
