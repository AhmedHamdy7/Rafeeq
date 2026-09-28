<?php

namespace App\Domains\Trip\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Enums\WaitTimerOutcome;
use Carbon\CarbonInterface;
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

    /**
     * When the passenger's time runs out — the grace plus everything the driver added.
     */
    public function expiresAt(): CarbonInterface
    {
        return $this->started_at->copy()
            ->addSeconds($this->grace_seconds + $this->extended_seconds);
    }

    public function hasExpired(): bool
    {
        return $this->expiresAt()->isPast();
    }

    /**
     * Still counting. A timer that was answered is not running whatever the clock says.
     */
    public function isRunning(): bool
    {
        return $this->outcome === null;
    }

    /**
     * Seconds left, negative once the grace has run out.
     *
     * Negative rather than clamped because the screen keeps showing the timer after it
     * expires — "grace ended" with a no-show button — and how long ago it ended is what a
     * driver decides on.
     */
    public function remainingSeconds(): int
    {
        return (int) round(now()->diffInSeconds($this->expiresAt(), absolute: false));
    }
}
