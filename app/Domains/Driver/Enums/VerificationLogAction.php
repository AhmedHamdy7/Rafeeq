<?php

namespace App\Domains\Driver\Enums;

enum VerificationLogAction: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case RequestInfo = 'request_info';
    case Suspend = 'suspend';

    /** The car review: approved seats, tier and AC set on a vehicle (F2-7, ADM-01). */
    case ReviewCar = 'review_car';
}
