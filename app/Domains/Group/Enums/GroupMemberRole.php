<?php

namespace App\Domains\Group\Enums;

enum GroupMemberRole: string
{
    case Driver = 'driver';
    case Member = 'member';
    case Trial = 'trial';
}
