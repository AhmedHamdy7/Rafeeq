<?php

namespace App\Http\Resources;

use App\Domains\Booking\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking, to either the passenger who holds it or the driver who approved it.
 *
 * 🔒 The exact meeting point appears only once the booking is CONFIRMED.
 *
 * That is one of the Bible's non-negotiable tests — "returns fuzzed pickup
 * coordinates before booking is confirmed", "returns exact coordinates only after
 * confirmation". The reason is concrete: a pickup point is often someone's front
 * door, and a pending or cancelled booking is not a relationship that has earned
 * a home address. Before confirmation the coordinates are rounded to about a
 * hundred metres, which is enough to show the right neighbourhood on a map and not
 * enough to knock on a door.
 *
 * The money is shown broken into its three frozen parts, because a passenger is
 * entitled to see what they are paying and what of it reaches the driver.
 *
 * @mixin Booking
 */
final class BookingResource extends JsonResource
{
    /**
     * ~110m. Coarse enough that a building cannot be picked out, fine enough that
     * the map shows the right area.
     */
    private const int FUZZ_DECIMALS = 3;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tripId' => $this->scheduled_trip_id,
            'commuteGroupId' => $this->commute_group_id,
            'status' => strtoupper($this->status->value),
            'seatsReserved' => $this->seats_reserved,

            /*
             * Frozen at approval and never recomputed. A driver raising their price
             * tomorrow, or the platform changing its percentage, moves nothing here.
             */
            'price' => [
                'totalPiastres' => $this->price_snapshot_piastres,
                'platformFeePiastres' => $this->platform_fee_snapshot_piastres,
                'driverAmountPiastres' => $this->driver_amount_snapshot_piastres,
            ],

            'paymentType' => $this->payment_type->value,
            'paymentStatus' => strtoupper($this->payment_status->value),

            // 🔒 Exact only once confirmed — see the class note.
            'meetingPoint' => $this->meetingPoint(),

            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            'cancelledReason' => $this->cancelled_reason,
            'cancellationFeePiastres' => $this->cancellation_fee_piastres,
            'createdAt' => $this->created_at->toIso8601String(),

            'trip' => new ScheduledTripResource($this->whenLoaded('scheduledTrip')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function meetingPoint(): ?array
    {
        $point = $this->pickup_point;

        if ($point === null) {
            return null;
        }

        // The rule itself lives on the enum, because the home screen's next-journey
        // card answers the same question about the plate number.
        $confirmed = $this->status->grantsExactDetails();

        return [
            'lat' => $confirmed ? $point->lat : round($point->lat, self::FUZZ_DECIMALS),
            'lng' => $confirmed ? $point->lng : round($point->lng, self::FUZZ_DECIMALS),
            // Stated rather than left for the client to infer from the status, so a
            // map can say "approximate" honestly.
            'isExact' => $confirmed,
        ];
    }
}
