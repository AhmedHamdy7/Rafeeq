<?php

namespace App\Domains\Commute\Enums;

enum CommutePausedReason: string
{
    case VehicleSuspended = 'vehicle_suspended';
    case LicenceExpired = 'licence_expired';
    case ByDriver = 'by_driver';

    /*
     * The driver's commission went unpaid through a second Friday, so she is suspended from
     * driving and every commute stops (PAY-45, decision D-0018). Automatic, like the two above,
     * and with no cancellation fee for anybody. Lifted by paying; she resumes it herself.
     */
    case CommissionUnpaid = 'commission_unpaid';
}
