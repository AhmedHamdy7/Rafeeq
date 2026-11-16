<?php

namespace App\Domains\Admin\Support;

/**
 * The dashboard's maps (C02, Y02, L01) on Google Maps.
 *
 * Off until `RAFEEQ_ADMIN_MAPS_ENABLED=true` AND a browser key is set. When off, nothing is
 * loaded and the pages show what they showed before: no script, no empty box, no request to
 * Google.
 *
 * 🔒 The browser key is a different key from `GOOGLE_MAPS_API_KEY` (the server's Routes key).
 * It ends up in every dashboard page, so it is restricted in Google Cloud to the Maps JavaScript
 * API and to the dashboard's domain (HTTP referrer). It is never committed: env only.
 */
final class AdminMaps
{
    public static function enabled(): bool
    {
        return config('rafeeq.admin_maps_enabled') === true && self::key() !== '';
    }

    public static function key(): string
    {
        return trim((string) config('services.google_maps.browser_key'));
    }

    /**
     * A commute's stops as map markers: origin A, pickups 1…n, drop-offs, destination B.
     *
     * @param  iterable<object{type: mixed, lat: mixed, lng: mixed, address: mixed}>  $locations
     * @return list<array{lat: float, lng: float, label: string, title: string, kind: string}>
     */
    public static function stops(iterable $locations): array
    {
        $markers = [];
        $pickup = 0;

        foreach ($locations as $location) {
            if ($location->lat === null || $location->lng === null) {
                continue;
            }

            $type = $location->type instanceof \BackedEnum ? $location->type->value : (string) $location->type;
            $label = match ($type) {
                'origin' => 'A',
                'destination' => 'B',
                'pickup' => (string) ++$pickup,
                default => '•',
            };

            $markers[] = ['lat' => (float) $location->lat, 'lng' => (float) $location->lng, 'label' => $label, 'title' => (string) $location->address, 'kind' => $type];
        }

        return $markers;
    }
}
