<?php

namespace App\Domains\Driver\Enums;

enum VehicleRejectedItem: string
{
    case Registration = 'vehicle_registration';
    case Details = 'vehicle_details';
}
