<?php

namespace App\Domains\Notification\Enums;

use App\Domains\Notification\Models\NotificationPreference;

/**
 * `Safety` can never be disabled via {@see NotificationPreference}
 * — enforced in the preference-update Action, not the schema (Bible §4).
 */
enum NotificationCategory: string
{
    case Booking = 'booking';
    case Payment = 'payment';
    case Trip = 'trip';
    case Safety = 'safety';
    case Marketing = 'marketing';
}
