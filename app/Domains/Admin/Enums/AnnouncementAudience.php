<?php

namespace App\Domains\Admin\Enums;

enum AnnouncementAudience: string
{
    case Everyone = 'everyone';
    case Passengers = 'passengers';
    case Drivers = 'drivers';
}
