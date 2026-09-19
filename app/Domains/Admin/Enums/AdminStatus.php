<?php

namespace App\Domains\Admin\Enums;

enum AdminStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
