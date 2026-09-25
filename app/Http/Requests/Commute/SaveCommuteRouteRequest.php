<?php

namespace App\Http\Requests\Commute;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The route of a commute (Chapter 4 §3).
 *
 * ---
 *
 * Maintainer notes:
 *
 * - A PUT, because the route is replaced as a whole. Patching individual points
 *   could leave an origin from one journey with a destination from another, and
 *   the sequence numbers would need reconciling anyway.
 * - "Origin cannot equal destination" is checked by distance in the Action, not
 *   by comparing coordinates: two pins a few metres apart are the same place as
 *   far as a journey is concerned.
 * - Each pickup is checked against the driver's own `maxDetourMinutes` rather
 *   than a fixed tolerance, so the limit is the one they chose.
 */
final class SaveCommuteRouteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Where the journey starts.
            'origin' => ['required', 'array'],
            'origin.lat' => ['required', 'numeric', 'between:-90,90'],
            'origin.lng' => ['required', 'numeric', 'between:-180,180'],
            'origin.address' => ['nullable', 'string', 'max:255'],
            // Optionally link to a known place, so the name shown stays
            // consistent with the rest of the platform.
            'origin.placeId' => ['nullable', 'string', 'size:26', 'exists:places,id'],

            // Where it ends. Must be a meaningfully different place from the
            // origin.
            'destination' => ['required', 'array'],
            'destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required', 'numeric', 'between:-180,180'],
            'destination.address' => ['nullable', 'string', 'max:255'],
            'destination.placeId' => ['nullable', 'string', 'size:26', 'exists:places,id'],

            // Meeting points along the way, in the order you would reach them.
            // Each must be within your stated detour tolerance.
            'pickups' => ['sometimes', 'array', 'max:'.config('rafeeq.commute.max_pickup_points')],
            'pickups.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickups.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'pickups.*.address' => ['nullable', 'string', 'max:255'],
            'pickups.*.placeId' => ['nullable', 'string', 'size:26', 'exists:places,id'],

            // Points where passengers may get out before the destination.
            'dropoffs' => ['sometimes', 'array', 'max:'.config('rafeeq.commute.max_dropoff_points')],
            'dropoffs.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoffs.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoffs.*.address' => ['nullable', 'string', 'max:255'],
            'dropoffs.*.placeId' => ['nullable', 'string', 'size:26', 'exists:places,id'],
        ];
    }
}
