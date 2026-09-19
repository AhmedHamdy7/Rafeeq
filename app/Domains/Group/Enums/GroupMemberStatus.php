<?php

namespace App\Domains\Group\Enums;

enum GroupMemberStatus: string
{
    case Active = 'active';
    case NoticeGiven = 'notice_given';
    case Left = 'left';
    case Removed = 'removed';
}
