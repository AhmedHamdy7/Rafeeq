<?php

namespace App\Http\Resources;

use App\Domains\Group\Models\GroupAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Somebody's declared intention for one day: "I'm coming" or "I'm away".
 *
 * ⚠️ Not the check-in. This is what a member said in advance so the driver can plan
 * their morning; whether they actually got in the car is `attendance` (Phase 9), and
 * that one drives billing. Conflating them is the mistake the ERD warns about twice.
 *
 * @mixin GroupAttendance
 */
final class GroupAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tripId' => $this->scheduled_trip_id,
            'status' => strtoupper($this->status->value),
            'markedAt' => $this->marked_at?->toIso8601String(),

            // 🔒 A public first name, as everywhere else a member appears.
            'person' => $this->when(
                $this->resource->relationLoaded('user'),
                fn () => [
                    'publicFirstName' => $this->user->public_first_name,
                    'trustLevel' => $this->user->trust_level,
                ],
            ),
        ];
    }
}
