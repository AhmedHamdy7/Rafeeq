<?php

namespace App\Domains\Driver\Enums;

enum VehicleSuspensionReason: string
{
    case RegistrationExpired = 'registration_expired';
    case Report = 'report';
}
