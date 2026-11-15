<?php

namespace App\Domains\Safety\Enums;

enum AutoShareMessageStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
