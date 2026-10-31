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

#[Fillable(['trip_session_id', 'booking_id', 'started_at', 'grace_seconds', 'arrived_at', 'arrival_distance_meters', 'grace_starts_at'])]
class TripWaitTimer extends Model
{
    /** @use HasFactory<TripWaitTimerFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'arrived_at' => 'datetime',
            'arrival_distance_meters' => 'integer',
            'grace_starts_at' => 'datetime',
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
     * When the passenger's time runs out.
     *
     * 🔴 Counted from `grace_starts_at` — the later of the driver's GPS-checked arrival and the
     * passenger's own pickup time (TRIP-11) — not from when the row was written. A driver who
     * arrives ten minutes early does not start somebody's five minutes ten minutes early.
     *
     * `extended_seconds` is still added: it is 0 on every timer since the "+2 min" extension was
     * removed (TRIP-11, B7), and older rows keep the time that was actually given.
     */
    public function expiresAt(): CarbonInterface
    {
        return ($this->grace_starts_at ?? $this->started_at)->copy()
            ->addSeconds($this->grace_seconds + $this->extended_seconds);
    }

    /**
     * Whether her grace is counting yet. False while a driver who arrived early waits for the
     * passenger's pickup time (TRIP-11).
     */
    public function graceHasStarted(): bool
    {
        return ! ($this->grace_starts_at ?? $this->started_at)->isFuture();
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
