<?php

namespace App\Domains\Geo\Enums;

enum PlaceType: string
{
    case CompoundGate = 'compound_gate';
    case Street = 'street';
    case Landmark = 'landmark';
    case Station = 'station';
    case Campus = 'campus';
    case Office = 'office';
}
