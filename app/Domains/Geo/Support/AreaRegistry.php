<?php

namespace App\Domains\Geo\Support;

use App\Domains\Geo\Models\Area;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Google district a point is in (review item 8). The app sends the area it found; when the
 * server has its own Geocoding key it reverse-geocodes the point once and keeps its own area, so a
 * modified app can't claim another district. Both languages are stored and kept for ever.
 */
class AreaRegistry
{
    public function __construct(private GoogleGeocoder $geocoder) {}

    public function resolve(PointArea $sent, Coordinate $point, bool $lookup = true): ?string
    {
        try {
            $checked = $lookup ? $this->serverArea($point) : $this->knownServerArea($point);

            if ($checked !== null) {
                return $checked;
            }

            return $sent->areaPlaceId === null
                ? null
                : $this->remember($sent->areaPlaceId, $sent->areaName, $sent->areaCity, app()->getLocale())->google_place_id;
        } catch (Throwable $e) {
            Log::warning('A point area could not be stored.', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The point columns every stored point carries next to its coordinates.
     *
     * @param  array<string, mixed>  $input
     * @return array{google_place_id: string|null, area_id: string|null}
     */
    public function columns(array $input, Coordinate $point): array
    {
        $sent = PointArea::from($input);

        return [
            'google_place_id' => $sent->googlePlaceId,
            'area_id' => $this->resolve($sent, $point),
        ];
    }

    public function knownServerArea(Coordinate $point): ?string
    {
        if (! $this->geocoder->available()) {
            return null;
        }

        $known = Cache::get(self::cacheKey($point));

        return is_string($known) ? $known : null;
    }

    public function serverArea(Coordinate $point): ?string
    {
        if (! $this->geocoder->available()) {
            return null;
        }

        $key = self::cacheKey($point);

        return Cache::remember($key, now()->addDays(30), function () use ($point): ?string {
            $found = $this->geocoder->areaAt($point, 'en');

            if ($found === null) {
                return null;
            }

            return $this->remember($found['placeId'], $found['name'], $found['city'], 'en', $found)->google_place_id;
        });
    }

    /**
     * @param  array{lat?: float|null, lng?: float|null, viewport?: array<string, mixed>|null}  $geometry
     */
    public function remember(string $placeId, ?string $name, ?string $city, string $locale, array $geometry = []): Area
    {
        $language = $locale === 'ar' ? 'ar' : 'en';
        $other = $language === 'ar' ? 'en' : 'ar';

        $area = Area::query()->find($placeId) ?? new Area(['google_place_id' => $placeId]);

        if ($name !== null) {
            $area->{"name_{$language}"} = $name;
        }

        if ($city !== null) {
            $area->{"city_{$language}"} = $city;
        }

        foreach (['lat', 'lng', 'viewport'] as $field) {
            if (($geometry[$field] ?? null) !== null && $area->{$field} === null) {
                $area->{$field} = $geometry[$field];
            }
        }

        if ($area->{"name_{$other}"} === null && $this->geocoder->available()) {
            $translated = $this->geocoder->place($placeId, $other);

            if ($translated !== null) {
                $area->{"name_{$other}"} = $translated['name'];
                $area->{"city_{$other}"} ??= $translated['city'];
                $area->lat ??= $translated['lat'];
                $area->lng ??= $translated['lng'];
                $area->viewport ??= $translated['viewport'];
            }
        }

        $area->save();

        return $area;
    }

    private static function cacheKey(Coordinate $point): string
    {
        return 'areas:at:'.round($point->lat, 4).','.round($point->lng, 4);
    }
}
