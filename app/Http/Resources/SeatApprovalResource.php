<?php

namespace App\Http\Resources;

use App\Domains\Booking\Support\SeatApproval;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a driver's "yes" produced.
 *
 * One shape for both kinds of request rather than two endpoints, because approval
 * is one operation: a trial gets one booking, a recurring member gets every day in
 * the horizon that could be seated. A client that can read this can read both.
 *
 * `skippedDays` is the part worth reading. A recurring approval across thirty days
 * will meet days that are already full, and the driver needs to see exactly which
 * ones did not take — the alternative is finding out when somebody is standing on
 * the pavement waiting for a car with no seat in it.
 *
 * @mixin SeatApproval
 */
final class SeatApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SeatApproval $approval */
        $approval = $this->resource;

        $skipped = [];

        // Built with an explicit loop rather than a map, so the published contract
        // describes a list of typed objects instead of an untyped associative blob.
        foreach ($approval->skipped as $date => $reason) {
            $skipped[] = ['tripDate' => $date, 'reason' => $reason];
        }

        return [
            'seatRequest' => new SeatRequestResource($approval->request),
            'membership' => new GroupMemberResource($approval->member),
            'bookings' => BookingResource::collection($approval->bookings),
            'seatedDays' => count($approval->bookings),
            'skippedDays' => $skipped,
        ];
    }
}
