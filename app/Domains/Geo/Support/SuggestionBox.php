<?php

namespace App\Domains\Geo\Support;

use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Builder;

final class SuggestionBox
{
    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public static function around(Builder $query, Coordinate $centre, int $metres): Builder
    {
        $dLat = $metres / 111320;
        $dLng = $metres / (111320 * max(0.01, cos(deg2rad($centre->lat))));

        return $query
            ->whereBetween('lat', [$centre->lat - $dLat, $centre->lat + $dLat])
            ->whereBetween('lng', [$centre->lng - $dLng, $centre->lng + $dLng]);
    }
}
