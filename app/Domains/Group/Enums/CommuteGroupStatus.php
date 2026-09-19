<?php

namespace App\Domains\Group\Enums;

enum CommuteGroupStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Disbanded = 'disbanded';
}
