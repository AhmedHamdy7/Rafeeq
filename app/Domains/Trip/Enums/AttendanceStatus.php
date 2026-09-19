<?php

namespace App\Domains\Trip\Enums;

enum AttendanceStatus: string
{
    case Pending = 'pending';
    case Present = 'present';
    case Late = 'late';
    case PassengerNoShow = 'passenger_no_show';
    case DriverNoShow = 'driver_no_show';
    case Cancelled = 'cancelled';
}
