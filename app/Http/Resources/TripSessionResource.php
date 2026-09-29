<?php

namespace App\Http\Resources;

use App\Domains\Trip\Models\TripSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A run in progress, to the driver driving it or a passenger on it.
 *
 * 🔒 What is absent: the GPS trail. `trip_locations` is the most privacy-sensitive table
 * in the product — a minute-by-minute record of where somebody was — and a passenger
 * needs the car's position NOW, not the history of where it has been. The live position
 * arrives over the broadcast channel; the trail exists for disputes and is read by
 * support, not by clients.
 *
 * @mixin TripSession
 */
final class TripSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tripId' => $this->scheduled_trip_id,
            'status' => strtoupper($this->current_status->value),

            // When the driver tapped "start", which is not when the car moved.
            'startedAt' => $this->started_at?->toIso8601String(),
            // When it moved. Null until the run is actually under way.
            'departedAt' => $this->departed_at?->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),

            'durationSeconds' => $this->duration_seconds,
            /*
             * Null until there are GPS points to measure it from, rather than the route's
             * planned distance — which would present a figure we did not observe as one
             * we did.
             */
            'distanceTravelledMeters' => $this->distance_travelled_meters,

            /*
             * Stated rather than left for the client to work out from the status, because
             * the client would have to hard-code which statuses count — and then disagree
             * with the server the first time one is added.
             */
            'isUnderway' => $this->current_status->isUnderway(),

            /*
             * When the last location point arrived. A client showing a live map needs to
             * know its dot is stale; without this it draws a car that stopped reporting
             * twenty minutes ago as though it were still there.
             */
            'lastLocationAt' => $this->last_location_at?->toIso8601String(),

            /*
             * Screen 39, "Route changed". The moment the run FIRST went off its published route,
             * and the furthest it got — when it started and how far it went, which are the two
             * things anybody asking about it wants to know.
             *
             * Null on the overwhelming majority of runs, which is the point: this is an exception
             * report, not a measurement. Checked only while the run is in progress, because on the
             * way to collect people being off the direct line is the job.
             */
            'deviationDetectedAt' => $this->deviation_detected_at?->toIso8601String(),
            'deviationDistanceMeters' => $this->deviation_distance_meters,

            'trip' => new ScheduledTripResource($this->whenLoaded('scheduledTrip')),
        ];
    }
}
