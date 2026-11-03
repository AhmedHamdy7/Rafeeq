<?php

namespace App\Http\Resources;

use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Geo\Models\Place;

final class LocalisedLabel
{
    public static function of(?string $text): ?string
    {
        return $text === null || $text === '' ? $text : __($text);
    }

    public static function place(?Place $place, ?string $stored): ?string
    {
        if ($place === null) {
            return $stored;
        }

        return app()->getLocale() === 'en' ? $place->name : $place->name_ar;
    }

    public static function location(?CommuteLocation $location): ?string
    {
        if ($location === null) {
            return null;
        }

        if (app()->getLocale() === 'en' && $location->relationLoaded('place') && $location->place !== null) {
            return $location->place->name;
        }

        return $location->address;
    }
}
