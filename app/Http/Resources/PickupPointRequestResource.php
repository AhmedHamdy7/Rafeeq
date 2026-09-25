<?php

namespace App\Http\Resources;

use App\Domains\Booking\Enums\PickupPointRequestStatus;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Support\PickupDetour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A proposed meeting point and where the conversation about it has got to.
 *
 * 🔒 The proposed point is fuzzed to about a hundred metres until the driver has
 * approved it, the same rule and the same reason as {@see BookingResource}: what a
 * passenger proposes is usually their front door, and a request the driver has not
 * yet agreed to is not a relationship that has earned a home address. Once approved
 * they are meeting there tomorrow morning, so the exact point is the point.
 *
 * `addedMinutes` and `addedKm` are OUR measurement of what the stop costs, never the
 * requester's claim — see {@see PickupDetour}. They are
 * shown to both sides: the driver is being asked to spend them, and the passenger is
 * entitled to see what they are asking for.
 *
 * @mixin PickupPointRequest
 */
final class PickupPointRequestResource extends JsonResource
{
    /** ~110m. Coarse enough not to identify a building. */
    private const int FUZZ_DECIMALS = 3;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seatRequestId' => $this->seat_request_id,
            'groupMemberId' => $this->group_member_id,
            'status' => strtoupper($this->status->value),
            'label' => $this->proposed_label,
            'proposedPoint' => $this->proposedPoint(),

            // What the stop costs the driver, as measured by us.
            'addedMinutes' => (float) $this->added_minutes,
            'addedKm' => (float) $this->added_km,

            // From when an approval applies. `next_trip` is the only value the
            // schema can record, since there is no column for a specific date.
            'effectiveFrom' => $this->effective_from,

            // Set when the driver countered with a place of their own. The passenger
            // has to accept it before anything moves.
            'alternativePlaceId' => $this->alternative_place_id,

            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function proposedPoint(): ?array
    {
        $point = $this->proposed_point;

        if ($point === null) {
            return null;
        }

        $agreed = $this->status === PickupPointRequestStatus::Approved;

        return [
            'lat' => $agreed ? $point->lat : round($point->lat, self::FUZZ_DECIMALS),
            'lng' => $agreed ? $point->lng : round($point->lng, self::FUZZ_DECIMALS),
            // Stated rather than inferred, so a map can say "approximate" honestly.
            'isExact' => $agreed,
        ];
    }
}
