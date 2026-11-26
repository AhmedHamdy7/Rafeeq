<?php

namespace App\Domains\Geo\Support;

use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GooglePlaceDetails
{
    public function available(): bool
    {
        return filled(config('services.google_maps.server_key'));
    }

    public function location(string $placeId): ?Coordinate
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-Goog-Api-Key' => (string) config('services.google_maps.server_key'),
                    'X-Goog-FieldMask' => 'location',
                ])
                ->acceptJson()
                ->get(rtrim((string) config('services.google_maps.places_endpoint'), '/').'/'.rawurlencode($placeId));
        } catch (Throwable $e) {
            Log::warning('places.details.unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $lat = $response->json('location.latitude');
        $lng = $response->json('location.longitude');

        if (! $response->successful() || ! is_numeric($lat) || ! is_numeric($lng)) {
            Log::warning('places.details.error', ['status' => $response->status()]);

            return null;
        }

        return new Coordinate((float) $lat, (float) $lng);
    }
}
