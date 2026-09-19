<?php

namespace App\Domains\Matching\Models;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteType;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Shared\Casts\SpatialPoint;
use App\Domains\Shared\ValueObjects\DaysMask;
use Database\Factories\CommuteDemandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔒 Secret — see the migration's note. No driver-facing Resource may ever
 * serialize this model.
 */
#[Fillable([
    'passenger_user_id', 'origin_point', 'destination_point', 'origin_lat', 'origin_lng',
    'dest_lat', 'dest_lng', 'origin_label', 'destination_label', 'commute_type', 'days_mask',
    'preferred_arrival_start', 'preferred_arrival_end', 'flexibility_minutes', 'max_walk_minutes',
    'max_detour_minutes', 'budget_monthly_piastres', 'audience_preference', 'wants_return_trip',
])]
class CommuteDemand extends Model
{
    /** @use HasFactory<CommuteDemandFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'origin_point' => SpatialPoint::class,
            'destination_point' => SpatialPoint::class,
            'origin_lat' => 'decimal:7',
            'origin_lng' => 'decimal:7',
            'dest_lat' => 'decimal:7',
            'dest_lng' => 'decimal:7',
            'commute_type' => CommuteType::class,
            'days_mask' => 'integer',
            'flexibility_minutes' => 'integer',
            'max_walk_minutes' => 'integer',
            'max_detour_minutes' => 'integer',
            'budget_monthly_piastres' => 'integer',
            'audience_preference' => CommuteAudience::class,
            'wants_return_trip' => 'boolean',
            'status' => CommuteDemandStatus::class,
            'expires_at' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_user_id');
    }

    public function daysMask(): DaysMask
    {
        return DaysMask::fromBits($this->days_mask);
    }

    public function isActive(): bool
    {
        return $this->status === CommuteDemandStatus::Active;
    }
}
