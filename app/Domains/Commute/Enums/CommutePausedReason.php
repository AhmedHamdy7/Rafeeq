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

    /*
     * Staff put her account on hold for conduct (AUTH-19, D-0018 Q-027, follow-up F1-3). Every
     * upcoming day is cancelled with no fee for anybody; lifting the hold does not resume it.
     */
    case AccountSuspended = 'account_suspended';
    case DriverSuspended = 'driver_suspended';

    case GenderReview = 'gender_review';

    /*
     * Staff paused it from the dashboard (C04), with a message the driver reads on the commute
     * (`pausedMessage`). Like the automatic reasons, upcoming seats are cancelled with no fee for
     * anybody. Only staff resume it: the driver's Resume answers COMMUTE_INVALID_TRANSITION.
     */
    case ByStaff = 'by_staff';
}
