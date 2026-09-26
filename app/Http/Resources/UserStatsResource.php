<?php

namespace App\Http\Resources;

use App\Domains\Identity\Models\UserStat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The caller's own numbers, for the profile screen.
 *
 * 🔴 Every rate is null when it has not been computed, never zero, and the distinction is
 * the whole design of this resource.
 *
 * A new passenger has no on-time rate. Sending `0` would put "0% on-time" on their own
 * profile and, through {@see PersonSummary}, in front of every driver deciding whether to
 * let them into a car — a reliability accusation earned by nobody, on their first day.
 * Counts behave the opposite way and default to 0, because "no trips yet" and "zero trips"
 * genuinely are the same statement.
 *
 * The rates are computed by a job that arrives with the trip lifecycle (Phase 9), so today
 * every one of these is null in practice. That is honest rather than broken: the client can
 * render "—" and will start showing real figures the day the job runs, with no change
 * here.
 *
 * @mixin UserStat
 */
final class UserStatsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // A missing row is the normal state for a new account — see StatsController.
        if ($this->resource === null) {
            return self::nothingYet();
        }

        return [
            'completedTripsAsPassenger' => (int) $this->completed_trips_as_passenger,
            'completedTripsAsDriver' => (int) $this->completed_trips_as_driver,

            // Null until rated. See the class note.
            'ratingAsPassenger' => $this->asFloat($this->avg_rating_as_passenger),
            'ratingAsDriver' => $this->asFloat($this->avg_rating_as_driver),
            'onTimeRate' => $this->asFloat($this->on_time_rate),
            'cancellationRate' => $this->asFloat($this->cancellation_rate),

            'noShowCount' => (int) $this->no_show_count,

            /*
             * When these were last worked out. Exposed so a client can say "as of
             * Tuesday" rather than implying the numbers are live — they are recomputed
             * periodically, not on every trip.
             */
            'computedAt' => $this->computed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nothingYet(): array
    {
        return [
            'completedTripsAsPassenger' => 0,
            'completedTripsAsDriver' => 0,
            'ratingAsPassenger' => null,
            'ratingAsDriver' => null,
            'onTimeRate' => null,
            'cancellationRate' => null,
            'noShowCount' => 0,
            'computedAt' => null,
        ];
    }

    /**
     * Cast explicitly rather than letting the decimal cast's string through: a field
     * typed `string` in the contract that holds "4.90" is the kind of thing a client
     * parses once and then multiplies by accident.
     */
    private function asFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
