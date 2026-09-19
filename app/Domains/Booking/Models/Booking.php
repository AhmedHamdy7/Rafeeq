<?php

namespace App\Domains\Booking\Models;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Geo\Models\Place;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Conversation;
use App\Domains\Payment\Models\Payment;
use App\Domains\Rating\Models\Rating;
use App\Domains\Shared\Casts\SpatialPoint;
use App\Domains\Trip\Models\Attendance;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Bible §Part 2, "أخطر عملية في النظام": every approval that creates one of
 * these must run inside `DB::transaction` with `ScheduledTrip::lockForUpdate()`
 * — enforced in the approval Action (Phase 7), not here.
 */
#[Fillable([
    'scheduled_trip_id', 'passenger_user_id', 'driver_profile_id', 'commute_group_id',
    'seat_request_id', 'seats_reserved', 'price_snapshot_piastres', 'platform_fee_snapshot_piastres',
    'driver_amount_snapshot_piastres', 'payment_type', 'pickup_place_id', 'pickup_point',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'seats_reserved' => 'integer',
            'price_snapshot_piastres' => 'integer',
            'platform_fee_snapshot_piastres' => 'integer',
            'driver_amount_snapshot_piastres' => 'integer',
            'payment_type' => PaymentType::class,
            'payment_status' => PaymentStatus::class,
            'status' => BookingStatus::class,
            'pickup_point' => SpatialPoint::class,
            'cancelled_at' => 'datetime',
            'cancellation_fee_piastres' => 'integer',
        ];
    }

    public function scheduledTrip(): BelongsTo
    {
        return $this->belongsTo(ScheduledTrip::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_user_id');
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id', 'user_id');
    }

    public function commuteGroup(): BelongsTo
    {
        return $this->belongsTo(CommuteGroup::class);
    }

    public function seatRequest(): BelongsTo
    {
        return $this->belongsTo(SeatRequest::class);
    }

    public function pickupPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'pickup_place_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function attendance(): HasOne
    {
        return $this->hasOne(Attendance::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function isActive(): bool
    {
        return ! $this->status->isTerminal();
    }
}
