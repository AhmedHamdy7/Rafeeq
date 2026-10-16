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
}
