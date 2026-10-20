<?php

namespace App\Domains\Trip\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Casts\SpatialPoint;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use App\Domains\Trip\Enums\NavigationStopKind;
use Database\Factories\NavigationStopFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stop on one day's navigation itinerary (Feature 03 v5 §5).
 *
 * No `$fillable`, and every column is written by {@see BuildNavigationItineraryAction}
 * or by the driver reaching the stop. A mass-assignable `sequence` or `point` would let a client
 * reorder the run or move a meeting place, and v5 is explicit that the backend supplies the
 * validated order and must *"not treat client-provided order as trusted"*.
 */
class NavigationStop extends Model
{
    /** @use HasFactory<NavigationStopFactory> */
    use HasFactory, HasUlids;

    protected $table = 'occurrence_navigation_stops';

    protected function casts(): array
    {
        return [
            'kind' => NavigationStopKind::class,
            'sequence' => 'integer',
            'point' => SpatialPoint::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'eta_at' => 'datetime',
            'leg_duration_seconds' => 'integer',
            'leg_distance_meters' => 'integer',
            'reached_at' => 'datetime',
            'itinerary_version' => 'integer',
        ];
    }

    public function scheduledTrip(): BelongsTo
    {
        return $this->belongsTo(ScheduledTrip::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Stops the driver has not reached yet, in order.
     *
     * v5 §3: the in-progress navigation *"reflects remaining actionable stops and current position,
     * not completed stops"*. A driver halfway through her run should not be shown the three kerbs
     * she has already pulled away from.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRemaining(Builder $query): void
    {
        $query->whereNull('reached_at')->orderBy('sequence');
    }
}
