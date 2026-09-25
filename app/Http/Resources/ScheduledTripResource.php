<?php

namespace App\Http\Resources;

use App\Domains\Commute\Models\ScheduledTrip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bookable day of a commute. Passengers always book these, never a
 * recurrence rule.
 *
 * Both the UTC instant and the local wall clock are returned. The instant is
 * what a client should compute from; the local time is what a person reads, and
 * the two do not differ by a constant across a DST transition.
 *
 * @mixin ScheduledTrip
 */
final class ScheduledTripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tripDate' => $this->trip_date->toDateString(),
            'departureAt' => $this->departure_at->toIso8601String(),
            'departureLocal' => $this->departure_local->format('Y-m-d H:i:s'),
            'status' => strtoupper($this->status->value),
            'seatsTotal' => $this->seats_total,
            'seatsTaken' => $this->seats_taken,
            // Cast on the outside: `max()` returns the wider of its arguments'
            // types, which the API contract would describe as untyped.
            'seatsAvailable' => (int) max(0, $this->seats_total - $this->seats_taken),
            // Snapshotted when the day was generated: a later price change does
            // not move it.
            'pricePerSeatPiastres' => $this->price_snapshot_piastres,
            'bookingDeadlineAt' => $this->booking_deadline_at?->toIso8601String(),
        ];
    }
}
