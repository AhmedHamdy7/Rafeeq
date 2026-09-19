<?php

namespace App\Domains\Geo\Models;

use App\Domains\Geo\Enums\CorridorStatus;
use App\Domains\Shared\ValueObjects\DaysMask;
use Database\Factories\CorridorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'name_ar', 'origin_place_id', 'destination_place_id',
    'window_start', 'window_end', 'days_mask',
])]
class Corridor extends Model
{
    /** @use HasFactory<CorridorFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'days_mask' => 'integer',
            'status' => CorridorStatus::class,
            'drivers_count' => 'integer',
            'seekers_count' => 'integer',
            'seat_fill_pct' => 'decimal:2',
        ];
    }

    public function originPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'origin_place_id');
    }

    public function destinationPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'destination_place_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(CorridorStat::class);
    }

    public function daysMask(): DaysMask
    {
        return DaysMask::fromBits($this->days_mask);
    }
}
