<?php

namespace App\Domains\Commute\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Trip\Models\NavigationStop;
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
    'departure_local', 'promised_arrival_at', 'seats_total', 'price_snapshot_piastres',
    'booking_deadline_at',
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
            // The arrival promised for this day. Null means no promise was made, which is not the
            // same as a promise of "any time" — nothing is scored against null.
            'promised_arrival_at' => 'datetime',
            'seats_total' => 'integer',
            'seats_taken' => 'integer',
            'price_snapshot_piastres' => 'integer',
            'status' => ScheduledTripStatus::class,
            'booking_deadline_at' => 'datetime',

            /*
             * Whether she is willing to be asked for a seat mid-journey (Feature 03 v5 §8's
             * `Receive Requests During Ride`). True unless she said otherwise.
             *
             * ⚠️ Nothing reads it yet — the active-ride request flow it governs is not built. See
             * the migration `add_receive_requests_to_scheduled_trips` on why her answer is
             * collected anyway.
             */
            'receives_requests_during_ride' => 'boolean',

            /*
             * Which rungs of the driver-not-started ladder have fired (Feature 03 v5 §240).
             * Each is set once and never cleared — see the migration
             * `add_delay_ladder_to_scheduled_trips` on why these are columns rather than a question
             * asked of the inbox.
             */
            'delay_driver_reminded_at' => 'datetime',
            'delay_passengers_warned_at' => 'datetime',
            'delay_violation_at' => 'datetime',

            /*
             * The navigation itinerary for this day (Feature 03 v5 §3).
             *
             * Two versions, not one: `itinerary_version` is bumped by anything that changes which
             * stops exist — cheap, no provider call, safe inside the seat lock — and
             * `itinerary_built_version` records what the stored stops were actually built from. The
             * read side rebuilds when the second trails the first. See
             * {@see \App\Domains\Trip\Actions\BuildNavigationItineraryAction}.
             */
            'itinerary_version' => 'integer',
            'itinerary_built_version' => 'integer',
            'itinerary_built_at' => 'datetime',
            'itinerary_distance_meters' => 'integer',
            'itinerary_duration_seconds' => 'integer',
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

    /**
     * The ordered stops the driver drives on this day, derived from the confirmed bookings.
     *
     * ⚠️ May be stale. `BuildNavigationItineraryAction::forTrip()` is what guarantees they match
     * the current bookings; reading this relation directly reads whatever was last built.
     */
    public function navigationStops(): HasMany
    {
        return $this->hasMany(NavigationStop::class)->orderBy('sequence');
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
