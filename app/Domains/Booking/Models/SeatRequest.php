<?php

namespace App\Domains\Booking\Models;

use App\Domains\Booking\Enums\MeetingPreference;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Enums\PointSource;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Models\Place;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Casts\SpatialPoint;
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

            /*
             * The two points she must choose to ask for a seat (Feature 03 v5 §2). Not fillable:
             * they are resolved and validated server-side by {@see RequestedPoints} — direction,
             * detour and whether a named suggestion is really this commute's — and a mass-assignable
             * pair would let a client write coordinates straight past those checks.
             */
            'requested_pickup_point' => SpatialPoint::class,
            'requested_pickup_lat' => 'decimal:7',
            'requested_pickup_lng' => 'decimal:7',
            'requested_pickup_source' => PointSource::class,
            'requested_dropoff_point' => SpatialPoint::class,
            'requested_dropoff_lat' => 'decimal:7',
            'requested_dropoff_lng' => 'decimal:7',
            'requested_dropoff_source' => PointSource::class,

            'agreed_to_rules_at' => 'datetime',
            'payment_type' => PaymentType::class,
            'status' => SeatRequestStatus::class,
            'waitlist_position' => 'integer',
            // Set when the platform took her seat away and put her back on the list — a paused
            // commute or a replaced vehicle. Null for everybody who joined by asking.
            'displaced_at' => 'datetime',
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

    /**
     * The driver-advertised stop she picked for her pickup, when she picked one rather than
     * dropping her own pin. Null for a custom map point.
     *
     * ⚠️ Not the authoritative record of where she is collected — the coordinates on this row are.
     * The link is `nullOnDelete` precisely so a driver tidying her advertised suggestions cannot
     * move or erase a meeting point somebody is relying on.
     */
    public function pickupSuggestion(): BelongsTo
    {
        return $this->belongsTo(CommuteLocation::class, 'requested_pickup_suggestion_id');
    }

    public function dropoffSuggestion(): BelongsTo
    {
        return $this->belongsTo(CommuteLocation::class, 'requested_dropoff_suggestion_id');
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
