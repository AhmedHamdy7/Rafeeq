<?php

namespace App\Domains\Booking\Enums;

enum MeetingPreference: string
{
    case Gate = 'gate';
    case Street = 'street';
    case Landmark = 'landmark';
    case Custom = 'custom';
}
