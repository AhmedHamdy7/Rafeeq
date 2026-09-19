<?php

namespace App\Domains\Commute\Models;

use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteDirection;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Enums\CommuteType;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Group\Models\CommuteGroup;
use Database\Factories\CommuteOfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'driver_profile_id', 'vehicle_id', 'corridor_id', 'commute_type', 'direction',
    'seats_total', 'price_per_seat_piastres', 'currency', 'max_detour_minutes',
    'max_walk_minutes', 'audience', 'min_trust_level', 'allows_custom_pickup',
])]
class CommuteOffer extends Model
{
    /** @use HasFactory<CommuteOfferFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'commute_type' => CommuteType::class,
            'status' => CommuteOfferStatus::class,
            'direction' => CommuteDirection::class,
            'seats_total' => 'integer',
            'price_per_seat_piastres' => 'integer',
            'max_detour_minutes' => 'integer',
            'max_walk_minutes' => 'integer',
            'audience' => CommuteAudience::class,
            'min_trust_level' => 'integer',
            'allows_custom_pickup' => 'boolean',
            'route_distance_meters' => 'integer',
            'route_duration_seconds' => 'integer',
            'bbox_min_lat' => 'decimal:7',
            'bbox_max_lat' => 'decimal:7',
            'bbox_min_lng' => 'decimal:7',
            'bbox_max_lng' => 'decimal:7',
            'published_at' => 'datetime',
            'paused_at' => 'datetime',
            'paused_reason' => CommutePausedReason::class,
            'archived_at' => 'datetime',
        ];
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id', 'user_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function corridor(): BelongsTo
    {
        return $this->belongsTo(Corridor::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(CommuteLocation::class);
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(CommuteSchedule::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CommuteRule::class);
    }

    public function scheduledTrips(): HasMany
    {
        return $this->hasMany(ScheduledTrip::class);
    }

    public function group(): HasOne
    {
        return $this->hasOne(CommuteGroup::class);
    }

    public function seatRequests(): HasMany
    {
        return $this->hasMany(SeatRequest::class);
    }

    public function isPublished(): bool
    {
        return $this->status === CommuteOfferStatus::Published;
    }

    public function acceptsWomenOnly(): bool
    {
        return $this->audience === CommuteAudience::WomenOnly;
    }
}
