<?php

namespace App\Domains\Driver\Enums;

enum VehicleVerificationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
