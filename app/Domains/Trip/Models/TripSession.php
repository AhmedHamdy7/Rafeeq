<?php

namespace App\Domains\Trip\Models;

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Trip\Enums\TripSessionStatus;
use Database\Factories\TripSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['scheduled_trip_id', 'started_at'])]
class TripSession extends Model
{
    /** @use HasFactory<TripSessionFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'current_status' => TripSessionStatus::class,
            'distance_travelled_meters' => 'integer',
            'duration_seconds' => 'integer',
            'deviation_detected_at' => 'datetime',
            'deviation_distance_meters' => 'integer',
            'last_location_at' => 'datetime',
        ];
    }

    public function scheduledTrip(): BelongsTo
    {
        return $this->belongsTo(ScheduledTrip::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(TripLocation::class);
    }

    public function waitTimers(): HasMany
    {
        return $this->hasMany(TripWaitTimer::class);
    }

    public function hasGpsDropout(int $thresholdSeconds = 60): bool
    {
        return $this->last_location_at !== null
            && $this->current_status === TripSessionStatus::InProgress
            && $this->last_location_at->diffInSeconds(now()) > $thresholdSeconds;
    }
}
