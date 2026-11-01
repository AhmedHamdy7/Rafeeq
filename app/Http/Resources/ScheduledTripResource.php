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
            /*
             * What one seat on this day costs, computed by RAFEEQ and frozen when the day was
             * generated (P-1, PAY-44): a later settings change does not move it.
             */
            'seatPricePiastres' => (int) $this->seat_price_piastres,
            /*
             * 🔒 What the driver keeps of each seat — sent ONLY to the commute's own driver, and
             * absent for everybody else (P-3, PAY-39). The commission rate is never sent.
             */
            'driverSharePiastres' => $this->when(
                SeatPriceView::viewerDrivesCommute($request, $this->commute_offer_id),
                fn (): int => (int) $this->driver_share_piastres,
            ),
            'bookingDeadlineAt' => $this->booking_deadline_at?->toIso8601String(),

            /*
             * 🔒 `receives_requests_during_ride` is deliberately NOT here, although it is a column
             * on this model. This shape reaches passengers — it is embedded in a booking and in the
             * live trip — and the driver's willingness to be interrupted mid-journey is an
             * operational setting of hers that no passenger screen has any use for. It is published
             * on the driver-only navigation route, which is where v5 §9 puts the toggle, and
             * returned from the PATCH that sets it.
             */
        ];
    }
}
