<?php

namespace App\Domains\Safety\Enums;

enum SosResolution: string
{
    case FalseAlarm = 'false_alarm';
    case Resolved = 'resolved';
    case EscalatedPolice = 'escalated_police';
}
