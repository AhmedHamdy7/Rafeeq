<?php

namespace App\Domains\Rating\Enums;

enum RatingTagValue: string
{
    case SafeDriving = 'safe_driving';
    case OnTime = 'on_time';
    case CleanCar = 'clean_car';
    case GreatCompany = 'great_company';
    case Comfortable = 'comfortable';
    case WouldRideAgain = 'would_ride_again';
}
