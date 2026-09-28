<?php

namespace App\Http\Requests\Trip;

use App\Domains\Trip\Support\TripSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Positions reported by the driver's phone.
 *
 * ---
 *
 * Maintainer notes:
 *
 * - An ARRAY of points, not one. A phone that loses signal in a tunnel buffers and sends the
 *   backlog on the other side; an endpoint taking one position would make the client choose
 *   between dropping the gap and firing six requests.
 * - `recordedAt` is the DEVICE's clock and is required. Stamping arrival time instead would
 *   draw a car that teleported out of the tunnel. Because the value is not ours, the Action
 *   bounds it — a point from the future or from before the run started is dropped rather
 *   than stored.
 * - The batch cap comes from settings, not from a literal here: it is the only thing
 *   stopping one request from queueing a hundred thousand rows for insert.
 */
final class RecordTripLocationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // One or more positions, oldest first or newest first — the server sorts them by
            // the device clock either way.
            'points' => ['required', 'array', 'min:1', 'max:'.TripSettings::locationBatchMax()],

            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],

            // The moment the phone took the reading, in ISO 8601.
            'points.*.recordedAt' => ['required', 'date'],

            /*
             * How confident the phone was, in metres. Optional because not every platform
             * reports it; when it is there, a hopeless figure means the reading came from
             * cell towers rather than satellites and the point is dropped.
             */
            'points.*.accuracyMeters' => ['nullable', 'integer', 'min:0', 'max:32767'],

            // Column is a smallint, so the ceiling is the column's and not a judgement about
            // how fast a car can go.
            'points.*.speedKmh' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ];
    }
}
