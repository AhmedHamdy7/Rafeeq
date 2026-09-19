<?php

namespace App\Domains\Commute\Enums;

enum CommutePausedReason: string
{
    case VehicleSuspended = 'vehicle_suspended';
    case LicenceExpired = 'licence_expired';
    case ByDriver = 'by_driver';
}
