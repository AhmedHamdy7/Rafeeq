<?php

namespace App\Domains\Geo\Support;

use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Support\Facades\Http;

class GoogleGeocoder
{
    private const array AREA_TYPES = [
        'sublocality_level_1',
        'sublocality',
        'administrative_area_level_3',
        'locality',
        'administrative_area_level_2',
    ];

    private const array WHOLE_CITIES = ['cairo', 'giza', 'القاهرة', 'الجيزة'];

    public function available(): bool
    {
        return filled(config('services.google_maps.server_key'));
    }

    /**
     * @return array{placeId: string, name: string, city: string|null, lat: float|null, lng: float|null, viewport: array<string, mixed>|null}|null
     */
    public function areaAt(Coordinate $point, string $language): ?array
    {
        return self::area($this->call(['latlng' => "{$point->lat},{$point->lng}", 'language' => $language]));
    }

    /**
     * @return array{placeId: string, name: string, city: string|null, lat: float|null, lng: float|null, viewport: array<string, mixed>|null}|null
     */
    public function place(string $placeId, string $language): ?array
    {
        $results = $this->call(['place_id' => $placeId, 'language' => $language]);
        $first = $results[0] ?? null;

        if ($first === null) {
            return null;
        }

        return self::shape($first, $placeId, self::cityOf($results));
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function call(array $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $response = Http::timeout(5)->get(config('services.google_maps.geocoding_endpoint'), [
            ...$query,
            'key' => config('services.google_maps.server_key'),
        ]);

        if (! $response->successful() || $response->json('status') !== 'OK') {
            return [];
        }

        return array_values((array) $response->json('results', []));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array{placeId: string, name: string, city: string|null, lat: float|null, lng: float|null, viewport: array<string, mixed>|null}|null
     */
    public static function area(array $results): ?array
    {
        foreach (self::AREA_TYPES as $type) {
            foreach ($results as $result) {
                if (! in_array($type, (array) ($result['types'] ?? []), true)) {
                    continue;
                }

                $name = self::componentName($result, $type);

                if ($name === null || ($type === 'locality' && in_array(mb_strtolower($name), self::WHOLE_CITIES, true))) {
                    continue;
                }

                return self::shape($result, (string) $result['place_id'], self::cityOf($results), $name);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{placeId: string, name: string, city: string|null, lat: float|null, lng: float|null, viewport: array<string, mixed>|null}|null
     */
    private static function shape(array $result, string $placeId, ?string $city, ?string $name = null): ?array
    {
        $name ??= self::componentName($result, null);

        if ($name === null) {
            return null;
        }

        $location = $result['geometry']['location'] ?? null;

        return [
            'placeId' => $placeId,
            'name' => $name,
            'city' => $city,
            'lat' => isset($location['lat']) ? (float) $location['lat'] : null,
            'lng' => isset($location['lng']) ? (float) $location['lng'] : null,
            'viewport' => $result['geometry']['viewport'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function componentName(array $result, ?string $type): ?string
    {
        foreach ((array) ($result['address_components'] ?? []) as $component) {
            if ($type === null || in_array($type, (array) ($component['types'] ?? []), true)) {
                $name = $component['short_name'] ?? $component['long_name'] ?? null;

                return is_string($name) && $name !== '' ? $name : null;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private static function cityOf(array $results): ?string
    {
        foreach (['locality', 'administrative_area_level_1'] as $type) {
            foreach ($results as $result) {
                foreach ((array) ($result['address_components'] ?? []) as $component) {
                    if (in_array($type, (array) ($component['types'] ?? []), true)) {
                        return $component['long_name'] ?? $component['short_name'] ?? null;
                    }
                }
            }
        }

        return null;
    }
}
