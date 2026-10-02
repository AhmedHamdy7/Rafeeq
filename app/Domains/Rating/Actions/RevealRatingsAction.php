<?php

namespace App\Domains\Rating\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Rating\Models\Rating;
use App\Domains\Rating\Support\RatingSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 The one place `visible_at` is ever written.
 *
 * A single writer for the same reason `SafetyEventLog` is one: this column IS the double-blind
 * protection (pitfall #26), and a second place that fills it is a second place that can fill it too
 * early. Both reveal paths live here, and both of them reveal in pairs.
 *
 * There are exactly two reasons a rating becomes readable, and the Bible names both: **both parties
 * have rated**, or **the window has passed**. Nothing else may set it.
 */
final readonly class RevealRatingsAction
{
    public function __construct(private RecomputeRatingStatsAction $stats) {}

    /**
     * Reveals both ratings on a booking, once both exist.
     *
     * 🔒 The same timestamp for both, in one transaction. The Bible's own wording is "visible_at =
     * now, for the two of them together" — and revealing one before the other, even by the length
     * of a request, would leave a window in which one person can read the other's rating while her
     * own is still hidden. That is the exact asymmetry the design exists to prevent.
     *
     * @return int how many were revealed
     */
    public function pair(Booking $booking): int
    {
        if (Rating::query()->where('booking_id', $booking->id)->count() < 2) {
            // One rating on its own waits for the window. Nothing to do, and nothing to report.
            return 0;
        }

        return $this->reveal(
            Rating::query()->where('booking_id', $booking->id)->whereNull('visible_at')->get()
        );
    }

    /**
     * Reveals one-sided ratings whose window has run out.
     *
     * This is the half that makes the feature work at all: if a reveal needed both sides, a
     * passenger who never rates would keep her driver's rating hidden for ever, and the quietest
     * way to suppress a bad review would be to not write one.
     *
     * Measured from the journey, not from when the rating was written — the window belongs to the
     * trip, and two ratings on the same booking must become visible on the same day however far
     * apart they were submitted.
     *
     * @return int how many were revealed
     */
    public function due(): int
    {
        $cutoff = now()->subDays(RatingSettings::windowDays());

        $overdue = Rating::query()
            ->whereNull('visible_at')
            ->whereHas('booking.scheduledTrip', fn ($query) => $query->where('departure_at', '<=', $cutoff))
            ->get();

        return $this->reveal($overdue);
    }

    /**
     * @param  Collection<int, Rating>  $ratings
     */
    private function reveal($ratings): int
    {
        if ($ratings->isEmpty()) {
            return 0;
        }

        $at = now();

        DB::transaction(function () use ($ratings, $at): void {
            foreach ($ratings as $rating) {
                // `forceFill`: `visible_at` is deliberately not fillable — see the class note.
                $rating->forceFill(['visible_at' => $at])->save();
            }
        });

        /*
         * 🔴 The averages are recomputed only now, AFTER the reveal, and that ordering is itself a
         * privacy rule rather than housekeeping. See RecomputeRatingStatsAction: an average that
         * moved when a hidden rating arrived would let anybody read that rating off the
         * arithmetic.
         *
         * Outside the transaction because it is a recomputation from committed rows, and holding
         * `user_stats` for the length of an aggregate over every rating a person has is how a
         * busy evening turns into lock contention on a table three screens read.
         */
        foreach ($ratings->pluck('reviewed_user_id')->unique() as $userId) {
            $this->stats->execute($userId);
        }

        return $ratings->count();
    }
}
