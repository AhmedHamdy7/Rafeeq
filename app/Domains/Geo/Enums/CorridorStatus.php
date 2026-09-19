<?php

namespace App\Domains\Geo\Enums;

enum CorridorStatus: string
{
    case Healthy = 'healthy';
    case DriverShort = 'driver_short';
    case CriticalGap = 'critical_gap';
    case EscortArmed = 'escort_armed';
}
