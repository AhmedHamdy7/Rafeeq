<?php

namespace App\Http\Resources;

use App\Domains\Commute\Models\CommuteSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * When a commute runs.
 *
 * `departureTime` comes back exactly as the driver entered it, with the timezone
 * beside it — a local wall clock, not a UTC instant. The instant for a given day
 * is on that day's trip, because it differs across a DST transition.
 *
 * @mixin CommuteSchedule
 */
final class CommuteScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Bitmask: Saturday 1, Sunday 2, Monday 4, Tuesday 8, Wednesday 16,
            // Thursday 32, Friday 64.
            'daysMask' => $this->days_mask,
            'departureTime' => $this->departure_time,
            'timezone' => $this->timezone,
            'startDate' => $this->start_date->toDateString(),
            'endDate' => $this->end_date->toDateString(),
            // How far ahead bookable days currently exist. A daily job rolls this
            // forward; it is not the end of the commute.
            'generatedUntil' => $this->generated_until?->toDateString(),
        ];
    }
}
