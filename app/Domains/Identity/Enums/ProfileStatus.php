<?php

namespace App\Domains\Identity\Enums;

enum ProfileStatus: string
{
    case NotStarted = 'not_started';
    case BasicComplete = 'basic_complete';
}
