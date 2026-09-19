<?php

namespace App\Domains\Notification\Enums;

enum ConversationStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
