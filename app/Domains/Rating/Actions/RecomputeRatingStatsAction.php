<?php

namespace App\Domains\Rating\Actions;

use App\Domains\Identity\Models\UserStat;
use App\Domains\Rating\Enums\ModerationStatus;
use App\Domains\Rating\Enums\RatingDirection;
use App\Domains\Rating\Models\Rating;

/**
 * The averages three screens read — `avg_rating_as_driver` and `avg_rating_as_passenger`.
 *
 * 🔴 **Only VISIBLE ratings count, and this is the subtle half of double-blind.**
 *
 * Filtering the rating QUERY is the obvious half and pitfall #26 says it plainly. The half that is
 * easy to miss is the arithmetic: if a hidden rating moved somebody's average, anybody watching the
 * average could read that rating off it. A driver on exactly 5.00 from four trips who sees 4.60
 * appear knows, to the star, what the passenger who has not been revealed yet said about her — and
 * she knows it before she has written hers. The query filter is worth nothing if the mean leaks
 * around it.
 *
 * So this runs on REVEAL, never on submit.
 *
 * 🔒 Hidden ratings are excluded for a different reason: a rating a moderator has taken down must
 * not keep pulling an average it is no longer allowed to state. `flagged` still counts — flagging
 * is a queue, not a verdict, and dropping a rating the moment somebody complained about it would
 * make "report every bad review" an effective way to launder a record.
 *
 * Recomputed from rows rather than kept by increment, like `on_time_rate` next door: an average
 * maintained incrementally drifts the first time a job runs twice, and the wrong value then sticks
 * with nothing to compare it against.
 */
final readonly class RecomputeRatingStatsAction
{
    public function execute(string $userId): void
    {
        $stat = UserStat::query()->firstOrNew(['user_id' => $userId]);

        /*
         * Explicit attribute writes, not mass assignment: `UserStat` has no `$fillable` because it
         * is only ever written by system jobs, so `fill()` would be silently discarded under
         * `preventSilentlyDiscardingAttributes` — or worse, accepted.
         */
        $stat->user_id = $userId;
        $stat->avg_rating_as_driver = $this->average($userId, RatingDirection::PassengerToDriver);
        $stat->avg_rating_as_passenger = $this->average($userId, RatingDirection::DriverToPassenger);
        $stat->computed_at = now();

        $stat->save();
    }

    /**
     * The mean of what this person was given, in one direction.
     *
     * Null rather than zero when nobody has rated them, and the distinction is the point: a new
     * driver has no rating, and a zero would show her a nought-star badge earned by nobody.
     */
    private function average(string $userId, RatingDirection $direction): ?float
    {
        $average = Rating::query()
            ->visible()
            ->where('reviewed_user_id', $userId)
            ->where('direction', $direction->value)
            // See the class note: a moderator's takedown stops counting, a complaint does not.
            ->where('moderation_status', '!=', ModerationStatus::Hidden->value)
            ->avg('stars');

        return $average === null ? null : round((float) $average, 2);
    }
}
