<?php

namespace App\Http\Resources;

use App\Domains\Group\Models\GroupAbsence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A planned absence: a member telling the group they will not be there for a stretch
 * of days without leaving it.
 *
 * `releasesSeat` is the substantive field. Set, the seat was actually given back and
 * offered to whoever was waiting; unset, the seat stayed theirs and the car travels
 * with it empty. A client showing these two the same way would be hiding the only
 * part of the decision that costs anybody anything.
 *
 * @mixin GroupAbsence
 */
final class GroupAbsenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fromDate' => $this->from_date->toDateString(),
            'toDate' => $this->to_date->toDateString(),
            'reason' => $this->reason,
            // Whether the seat went back on offer for those days.
            'releasesSeat' => $this->releases_seat,
            'createdAt' => $this->created_at->toIso8601String(),

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
