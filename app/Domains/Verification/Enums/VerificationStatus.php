<?php

namespace App\Domains\Verification\Enums;

enum VerificationStatus: string
{
    case NotStarted = 'not_started';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ActionNeeded = 'action_needed';
    case Expired = 'expired';
}
