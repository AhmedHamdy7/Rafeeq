<?php

namespace App\Domains\Identity\Enums;

enum UserPlaceLabel: string
{
    case Home = 'home';
    case Work = 'work';
    case University = 'university';
    case Custom = 'custom';
}
