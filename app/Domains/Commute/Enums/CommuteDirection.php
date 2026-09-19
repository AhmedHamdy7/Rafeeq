<?php

namespace App\Domains\Commute\Enums;

enum CommuteDirection: string
{
    case ToWork = 'to_work';
    case ToHome = 'to_home';
}
