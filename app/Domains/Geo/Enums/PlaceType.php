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
    case District = 'district';
    case City = 'city';

    public function isArea(): bool
    {
        return $this === self::District || $this === self::City;
    }

    /**
     * @return list<string>
     */
    public static function areaValues(): array
    {
        return [self::District->value, self::City->value];
    }
}
