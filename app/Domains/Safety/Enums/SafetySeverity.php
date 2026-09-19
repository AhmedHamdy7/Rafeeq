<?php

namespace App\Domains\Safety\Enums;

enum SafetySeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
