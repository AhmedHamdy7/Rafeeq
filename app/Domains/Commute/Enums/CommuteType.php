<?php

namespace App\Domains\Commute\Enums;

enum CommuteType: string
{
    case Recurring = 'recurring';
    case OneTime = 'one_time';
}
