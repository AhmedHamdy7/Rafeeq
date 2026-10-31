<?php

namespace App\Http\Resources;

use App\Domains\Trip\Models\TripWaitTimer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The clock on screen 41, while a driver waits at a gate.
 *
 * @mixin TripWaitTimer
 */
final class WaitTimerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bookingId' => $this->booking_id,

            'startedAt' => $this->started_at->toIso8601String(),

            /*
             * "I've arrived", as the server checked it (TRIP-10): when, and how far from this
             * passenger's pickup the driver was. Null only on timers from before the check existed.
             */
            'arrivedAt' => $this->arrived_at?->toIso8601String(),
            'arrivalDistanceMeters' => $this->arrival_distance_meters,

            /*
             * When her five minutes began: the later of the arrival and her own pickup time
             * (TRIP-11). A driver who is early waits for the pickup time first, and the countdown
             * only starts then.
             */
            'graceStartsAt' => ($this->grace_starts_at ?? $this->started_at)->toIso8601String(),

            /*
             * The moment the grace runs out, which is what the client should count down to.
             * `remainingSeconds` is the SERVER's answer at this instant, sent for the same
             * reason the departure countdown is: a phone with a skewed clock would run the
             * timer to the wrong second, and this timer decides whether somebody is recorded
             * absent.
             *
             * Negative once it has expired rather than clamped, because the screen keeps
             * showing it afterwards — "grace ended", with the no-show button — and how long
             * ago it ended is what the driver is deciding on.
             */
            'expiresAt' => $this->expiresAt()->toIso8601String(),
            'remainingSeconds' => $this->remainingSeconds(),

            /*
             * The grace in force when the wait began. Every passenger gets the same one — there is
             * no driver extension any more (TRIP-11, B7).
             */
            'graceSeconds' => $this->grace_seconds,

            'hasExpired' => $this->hasExpired(),
            'isRunning' => $this->isRunning(),

            /*
             * Null while it is still counting. `no_show` and `driver_left` are deliberately
             * different values — see WaitTimerOutcome. A dispute turns on which.
             */
            'outcome' => $this->outcome?->value,

            'person' => $this->when(
                $this->resource->relationLoaded('booking') && $this->booking->relationLoaded('passenger'),
                fn () => PersonSummary::for($this->booking->passenger, $request->user()),
            ),
        ];
    }
}
