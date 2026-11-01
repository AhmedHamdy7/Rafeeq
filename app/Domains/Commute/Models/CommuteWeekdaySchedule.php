<?php

namespace App\Domains\Commute\Models;

use Database\Factories\CommuteWeekdayScheduleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekday of a commute — its own departure, promised arrival and capacity (Feature 03 v3).
 *
 * There is no per-weekday price any more: the seat price is automatic and comes from the route and
 * the car (PAY-42, D-0016), which are the same on every day.
 *
 * 🔴 The nullable `seats_total` is the design, not laziness. It is null for the ordinary driver who
 * offers the same every day, and null means "follow the commute". Copying the parent's value into
 * every row would look tidier and would silently stop following it the first time he changed it.
 *
 * No `$fillable`: every column is written by `SaveCommuteScheduleAction` from a validated payload,
 * and `weekday_bit` is derived from the days she picked rather than submitted on its own.
 */
class CommuteWeekdaySchedule extends Model
{
    /** @use HasFactory<CommuteWeekdayScheduleFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'weekday_bit' => 'integer',
            'seats_total' => 'integer',
        ];
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }

    public function seatsFor(CommuteOffer $offer): int
    {
        return $this->seats_total ?? (int) $offer->seats_total;
    }
}
