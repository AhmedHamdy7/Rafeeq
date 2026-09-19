<?php

namespace App\Domains\Identity\Enums;

enum SecurityRiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
