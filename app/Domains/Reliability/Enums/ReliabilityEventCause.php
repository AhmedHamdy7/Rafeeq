<?php

namespace App\Domains\Reliability\Enums;

enum ReliabilityEventCause: string
{
    case CancelledBooking = 'cancelled_booking';
    case Replacement = 'replacement';
    case CancelledDay = 'cancelled_day';
    case Pause = 'pause';
    case CommuteDeleted = 'commute_deleted';
    case VehicleReplaced = 'vehicle_replaced';
    case ActiveTripCancelled = 'active_trip_cancelled';
}
