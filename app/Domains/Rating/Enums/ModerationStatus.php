<?php

namespace App\Domains\Rating\Enums;

enum ModerationStatus: string
{
    case Clean = 'clean';
    case Flagged = 'flagged';
    case Hidden = 'hidden';
}
