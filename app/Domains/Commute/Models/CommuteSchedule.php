<?php

namespace App\Domains\Commute\Models;

use App\Domains\Shared\ValueObjects\DaysMask;
use Database\Factories\CommuteScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['commute_offer_id', 'days_mask', 'departure_time', 'timezone', 'start_date', 'end_date'])]
class CommuteSchedule extends Model
{
    /** @use HasFactory<CommuteScheduleFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'days_mask' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'generated_until' => 'date',
        ];
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }

    public function scheduledTrips(): HasMany
    {
        return $this->hasMany(ScheduledTrip::class);
    }

    public function daysMask(): DaysMask
    {
        return DaysMask::fromBits($this->days_mask);
    }
}
