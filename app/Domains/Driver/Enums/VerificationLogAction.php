<?php

namespace App\Domains\Driver\Enums;

enum VerificationLogAction: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case RequestInfo = 'request_info';
    case Suspend = 'suspend';
}
