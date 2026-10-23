<?php

namespace App\Domains\Booking\Models;

use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use Database\Factories\SeatOfferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One offer of a freed seat to one person on the waiting list.
 *
 * No `$fillable`: every column is written by `OfferSeatToWaitlistAction` or
 * `AcceptSeatOfferAction`. A mass-assignable `status` here would let a client accept an offer by
 * including a field in an unrelated payload — which is the whole seat.
 */
class SeatOffer extends Model
{
    /** @use HasFactory<SeatOfferFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => SeatOfferStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function scheduledTrip(): BelongsTo
    {
        return $this->belongsTo(ScheduledTrip::class);
    }

    public function seatRequest(): BelongsTo
    {
        return $this->belongsTo(SeatRequest::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', SeatOfferStatus::Open->value);
    }

    /**
     * Does she already have a live chance at this day?
     *
     * 🔴 The check that replaced an index doing two jobs. `seat_offers` used to be unique on
     * `(scheduled_trip_id, seat_request_id)`, which forbade a second offer for one day FOR EVER —
     * so when a second seat freed on a Tuesday whose first fan-out had already closed, nobody was
     * offered it and the car went out with an empty seat. The key now guards the release, which is
     * the thing that must not repeat, and "not two live offers at once" is asked here, where it can
     * be read.
     *
     * Only an OPEN offer counts. A closed one is history — including one she declined, which is
     * precisely the case v5 wants reoffered: *"Only declines this offer, doesn't remove
     * membership."*
     */
    public static function hasLiveOfferFor(string $scheduledTripId, string $seatRequestId): bool
    {
        return static::query()
            ->where('scheduled_trip_id', $scheduledTripId)
            ->where('seat_request_id', $seatRequestId)
            ->open()
            ->exists();
    }
}
