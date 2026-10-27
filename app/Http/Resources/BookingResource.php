<?php

namespace App\Http\Resources;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Support\DriverDelay;
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
             * The code to check against the car at the kerb, shown beside the plate.
             *
             * 🔒 Returned to BOTH sides on purpose, and that is what makes it usable: a code only
             * the passenger can see is a code nobody can confirm. She asks, the driver reads it
             * off her own list, and the plate and the number agree or they do not.
             *
             * This Resource is already scoped to the two people on the booking — the passenger who
             * holds it and the driver who approved it — so there is nobody else it reaches.
             *
             * ⚠️ It decides nothing: D18 gives attendance to the driver's tap, and the Master Plan
             * rejected a code for that job ("a PIN fails when somebody is running for the car").
             */
            'boardingCode' => $this->boarding_code,

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

            /*
             * Where she is set down, as agreed for this day (Feature 03 v5 §2).
             *
             * 🔒 Fuzzed before confirmation on exactly the same terms as the pickup, and the
             * reason is the same one the class note gives for a meeting point: a drop-off is often
             * a workplace, and a precise one handed out on a pending booking tells a stranger
             * where to wait for her at the other end of the day.
             */
            'dropoffPoint' => $this->dropoffPoint(),

            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            'cancelledReason' => $this->cancelled_reason,
            'cancellationFeePiastres' => $this->cancellation_fee_piastres,
            'createdAt' => $this->created_at->toIso8601String(),

            /*
             * Whether the driver has let her down badly enough that leaving is free right now
             * (Feature 03 v5 §241), and which way — `not_started` or `passed_your_stop`.
             *
             * 🔴 Null is the normal answer and means "do not show the button". The entitlement is
             * the server's finding, not a claim a client can make, so this is the only way a client
             * can know whether `POST /bookings/{booking}/exit-without-fee` would be accepted — and
             * the two reasons read completely differently on a screen: one is "your driver has not
             * set off", the other is "your driver has passed your stop", and only the second means
             * stop waiting.
             *
             * Computed, never stored: it is true only for as long as it is true, and a column would
             * go stale the moment the driver finally set off.
             */
            'driverDelay' => $this->when(
                $this->resource->relationLoaded('scheduledTrip') && $this->scheduledTrip !== null,
                fn (): ?string => $this->status === BookingStatus::Confirmed
                    ? DriverDelay::reasonFor($this->resource, $this->scheduledTrip)
                    : null,
            ),

            'trip' => new ScheduledTripResource($this->whenLoaded('scheduledTrip')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function meetingPoint(): ?array
    {
        return $this->placeOrNull($this->pickup_point, $this->pickup_label);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dropoffPoint(): ?array
    {
        return $this->placeOrNull($this->dropoff_point, $this->dropoff_label);
    }

    /**
     * One agreed place, fuzzed until the booking is confirmed.
     *
     * Shared by both ends of the journey rather than written twice: they are the same privacy rule
     * about the same kind of fact, and a rule written twice is one that will one day disagree with
     * itself.
     *
     * @return array<string, mixed>|null
     */
    private function placeOrNull(mixed $point, ?string $label): ?array
    {
        if ($point === null) {
            return null;
        }

        // The rule itself lives on the enum, because the home screen's next-journey
        // card answers the same question about the plate number.
        $confirmed = $this->status->grantsExactDetails();

        return [
            'lat' => $confirmed ? $point->lat : round($point->lat, self::FUZZ_DECIMALS),
            'lng' => $confirmed ? $point->lng : round($point->lng, self::FUZZ_DECIMALS),
            /*
             * 🔒 The label is withheld until confirmation too. "14 Mohamed Mahmoud St, flat 3" is
             * a precise address no matter how coarsely the coordinates beside it are rounded —
             * fuzzing the pin and publishing the street name would make the fuzzing decorative.
             */
            'label' => $confirmed ? $label : null,
            // Stated rather than left for the client to infer from the status, so a
            // map can say "approximate" honestly.
            'isExact' => $confirmed,
        ];
    }
}
