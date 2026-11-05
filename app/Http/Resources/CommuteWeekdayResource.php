<?php

namespace App\Http\Resources;

use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommuteWeekdaySchedule
 */
final class CommuteWeekdayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'weekdayBit' => (int) $this->weekday_bit,
            'promisedArrivalTime' => $this->promised_arrival_time === null ? null : (string) $this->promised_arrival_time,
            'departureTime' => (string) $this->departure_time,
            'departureIsComputed' => (bool) $this->departure_is_computed,
            'seatsTotal' => $this->seats_total === null ? null : (int) $this->seats_total,
        ];
    }
}
