<?php

namespace App\Domains\Booking\Models;

use App\Domains\Booking\Enums\MeetingPreference;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Models\Place;
use App\Domains\Identity\Models\User;
use Database\Factories\SeatRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'passenger_user_id', 'commute_offer_id', 'scheduled_trip_id', 'commitment',
    'requested_days_mask', 'seats', 'meeting_preference', 'custom_pickup_place_id',
    'intro_message', 'agreed_to_rules_at', 'payment_type',
])]
class SeatRequest extends Model
{
    /** @use HasFactory<SeatRequestFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'commitment' => SeatRequestCommitment::class,
            'requested_days_mask' => 'integer',
            'seats' => 'integer',
            'meeting_preference' => MeetingPreference::class,
            'agreed_to_rules_at' => 'datetime',
            'payment_type' => PaymentType::class,
            'status' => SeatRequestStatus::class,
            'waitlist_position' => 'integer',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_user_id');
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }

    public function scheduledTrip(): BelongsTo
    {
        return $this->belongsTo(ScheduledTrip::class);
    }

    public function customPickupPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'custom_pickup_place_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function pickupPointRequests(): HasMany
    {
        return $this->hasMany(PickupPointRequest::class);
    }

    public function isPending(): bool
    {
        return $this->status === SeatRequestStatus::Pending;
    }
}
