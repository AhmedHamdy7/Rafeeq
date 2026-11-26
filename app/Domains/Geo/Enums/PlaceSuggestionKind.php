<?php

namespace App\Domains\Geo\Enums;

enum PlaceSuggestionKind: string
{
    case CommutePoint = 'commute_point';
    case GooglePlace = 'google_place';
    case SharedPin = 'shared_pin';
}
