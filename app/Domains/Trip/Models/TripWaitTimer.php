<?php

namespace App\Domains\Trip\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Enums\WaitTimerOutcome;
use Database\Factories\TripWaitTimerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['trip_session_id', 'booking_id', 'started_at', 'grace_seconds'])]
class TripWaitTimer extends Model
{
    /** @use HasFactory<TripWaitTimerFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'grace_seconds' => 'integer',
            'extended_seconds' => 'integer',
            'outcome' => WaitTimerOutcome::class,
        ];
    }

    public function tripSession(): BelongsTo
    {
        return $this->belongsTo(TripSession::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function hasExpired(): bool
    {
        return $this->started_at->addSeconds($this->grace_seconds + $this->extended_seconds)->isPast();
    }
}
