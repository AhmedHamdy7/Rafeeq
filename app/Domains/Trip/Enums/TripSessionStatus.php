<?php

namespace App\Domains\Trip\Enums;

enum TripSessionStatus: string
{
    case Preparing = 'preparing';
    case EnRoute = 'en_route';
    case AtPickup = 'at_pickup';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Emergency = 'emergency';
}
