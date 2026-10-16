<?php

namespace App\Domains\Commute\Models;

use Database\Factories\CommuteWeekdayScheduleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekday of a commute — its own departure, promised arrival, price and capacity
 * (Feature 03 v3).
 *
 * 🔴 The nullable columns are the design, not laziness. `price_per_seat_piastres` and `seats_total`
 * are null for the ordinary driver who charges the same every day, and null means "follow the
 * commute". Copying the parent's values into every row would look tidier and would silently stop
 * following it the first time she changed her price.
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
            'price_per_seat_piastres' => 'integer',
            'seats_total' => 'integer',
        ];
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }

    /**
     * What a seat costs on this weekday, falling back to the commute.
     *
     * 🔴 Resolved here rather than at each call site, because there are three — trip generation,
     * the publish preview and the search payload — and a fallback that is spelled out three times
     * is a fallback that will eventually differ in one of them.
     */
    public function priceFor(CommuteOffer $offer): int
    {
        return $this->price_per_seat_piastres ?? (int) $offer->price_per_seat_piastres;
    }

    public function seatsFor(CommuteOffer $offer): int
    {
        return $this->seats_total ?? (int) $offer->seats_total;
    }
}
