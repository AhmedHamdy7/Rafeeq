<?php

namespace App\Domains\Identity\Enums;

enum ConsentSource: string
{
    case Registration = 'registration';
    case Settings = 'settings';
    case ForcedReaccept = 'forced_reaccept';
}
