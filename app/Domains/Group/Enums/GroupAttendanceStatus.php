<?php

namespace App\Domains\Group\Enums;

enum GroupAttendanceStatus: string
{
    case Coming = 'coming';
    case Away = 'away';
    case NoResponse = 'no_response';
}
