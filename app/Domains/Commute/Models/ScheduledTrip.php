<?php

namespace App\Domains\Commute\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Trip\Models\TripSession;
use Database\Factories\ScheduledTripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single day, generated from a `CommuteSchedule` (Bible's golden rule:
 * passengers book THIS, never the offer or the schedule directly).
 * `seats_taken` is the column every booking approval locks with
 * `lockForUpdate()` — see pitfall #1, enforced Phase 7.
 */
#[Fillable([
    'commute_offer_id', 'commute_schedule_id', 'trip_date', 'departure_at',
    'departure_local', 'seats_total', 'price_snapshot_piastres', 'booking_deadline_at',
])]
class ScheduledTrip extends Model
{
    /** @use HasFactory<ScheduledTripFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
            'departure_at' => 'datetime',
            'departure_local' => 'datetime',
            'seats_total' => 'integer',
            'seats_taken' => 'integer',
            'price_snapshot_piastres' => 'integer',
            'status' => ScheduledTripStatus::class,
            'booking_deadline_at' => 'datetime',
        ];
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }

    public function commuteSchedule(): BelongsTo
    {
        return $this->belongsTo(CommuteSchedule::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function tripSession(): HasOne
    {
        return $this->hasOne(TripSession::class);
    }

    public function hasSeatsAvailable(int $seats = 1): bool
    {
        return $this->seats_taken + $seats <= $this->seats_total;
    }

    public function seatsRemaining(): int
    {
        return max(0, $this->seats_total - $this->seats_taken);
    }
}
