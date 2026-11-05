<?php

namespace App\Domains\Booking\Enums;

use App\Domains\Commute\Support\RouteSettings;

enum DetourTier: string
{
    case OnWay = 'on_way';
    case Far = 'far';

    public static function for(int $pointMeters, float $requestMinutes, float $tripMinutes): ?self
    {
        if ($pointMeters <= RouteSettings::matchRadiusMeters()
            && $requestMinutes <= RouteSettings::onWayRequestMinutes()
            && $tripMinutes <= RouteSettings::onWayTripMinutes()) {
            return self::OnWay;
        }

        if ($pointMeters <= RouteSettings::farRadiusMeters()
            && $requestMinutes <= RouteSettings::farRequestMinutes()
            && $tripMinutes <= RouteSettings::farTripMinutes()) {
            return self::Far;
        }

        return null;
    }
}
