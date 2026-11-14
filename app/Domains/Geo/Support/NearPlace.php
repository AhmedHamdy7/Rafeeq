<?php

namespace App\Domains\Geo\Support;

use App\Domains\Geo\Models\Place;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Support\Facades\Cache;

final class NearPlace
{
    private const string CACHE_KEY = 'geo.near_place.catalogue';

    /** @var array<string, array{nearPlaceId: ?string, nearLabel: ?string, nearDistanceMetres: ?int}> */
    private static array $memo = [];

    /**
     * @return array{nearPlaceId: ?string, nearLabel: ?string, nearDistanceMetres: ?int}
     */
    public static function fields(mixed $lat, mixed $lng): array
    {
        $none = ['nearPlaceId' => null, 'nearLabel' => null, 'nearDistanceMetres' => null];

        if ($lat === null || $lng === null) {
            return $none;
        }

        $point = new Coordinate((float) $lat, (float) $lng);
        $limit = (int) config('rafeeq.geo.near_label_metres');
        $latReach = $limit / 111_320;

        $best = null;
        $bestMetres = null;

        foreach (self::catalogue() as $place) {
            if (abs($place['lat'] - $point->lat) > $latReach) {
                continue;
            }

            $metres = Haversine::between($point, new Coordinate($place['lat'], $place['lng']))->metres;

            if ($metres <= $limit && ($bestMetres === null || $metres < $bestMetres)) {
                $best = $place;
                $bestMetres = $metres;
            }
        }

        if ($best === null) {
            return $none;
        }

        return [
            'nearPlaceId' => $best['id'],
            'nearLabel' => app()->getLocale() === 'en' ? $best['name'] : $best['nameAr'],
            'nearDistanceMetres' => (int) $bestMetres,
        ];
    }

    /**
     * The three fields one at a time, for array literals in API resources. Spreading fields()
     * into a resource (`...NearPlace::fields()`) left the OpenAPI document unable to type those
     * keys, so every point in the API was documented as an untyped hole. Each of these has a
     * declared return type the document reads, and they share one lookup per point.
     */
    public static function id(mixed $lat, mixed $lng): ?string
    {
        return self::memo($lat, $lng)['nearPlaceId'];
    }

    public static function label(mixed $lat, mixed $lng): ?string
    {
        return self::memo($lat, $lng)['nearLabel'];
    }

    public static function metres(mixed $lat, mixed $lng): ?int
    {
        return self::memo($lat, $lng)['nearDistanceMetres'];
    }

    /**
     * @return array{nearPlaceId: ?string, nearLabel: ?string, nearDistanceMetres: ?int}
     */
    private static function memo(mixed $lat, mixed $lng): array
    {
        if ($lat === null || $lng === null) {
            return self::fields(null, null);
        }

        $key = ((float) $lat).','.((float) $lng).','.app()->getLocale().','.config('rafeeq.geo.near_label_metres');

        if (count(self::$memo) > 500) {
            self::$memo = [];
        }

        return self::$memo[$key] ??= self::fields($lat, $lng);
    }

    public static function forget(): void
    {
        self::$memo = [];
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<array{id: string, name: string, nameAr: string, lat: float, lng: float}>
     */
    private static function catalogue(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn (): array => Place::query()
            ->where('is_public', true)
            ->get(['id', 'name', 'name_ar', 'lat', 'lng'])
            ->map(fn (Place $place): array => [
                'id' => $place->id,
                'name' => $place->name,
                'nameAr' => $place->name_ar,
                'lat' => (float) $place->lat,
                'lng' => (float) $place->lng,
            ])
            ->all());
    }
}
