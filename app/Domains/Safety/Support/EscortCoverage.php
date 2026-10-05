<?php

namespace App\Domains\Safety\Support;

use App\Domains\Safety\Models\EscortWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which corridors are under night escort, and when "tonight" is.
 *
 * 🔴 "Armed" is a fact about time, not a column. A corridor is under escort while a window
 * covering this minute exists — nothing writes `escort_armed` into `corridors.status`, because a
 * status somebody sets is a status somebody forgets to unset, and the morning after, a corridor
 * that reads "armed" with nobody watching is worse than one that says nothing (Screen Map §8.0.1).
 */
final class EscortCoverage
{
    /**
     * The night window that is running now, or the next one if it is daytime — computed in Cairo
     * wall-clock hours and returned in the application's timezone, the one the rows are stored in.
     *
     * At 02:00 "tonight" is the night that started yesterday at 21:00; at 15:00 it is the one that
     * starts in six hours. Either way the answer is the window a person on the road would mean.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function tonight(?CarbonImmutable $at = null): array
    {
        $local = ($at ?? CarbonImmutable::now())->setTimezone('Africa/Cairo');

        $starts = SafetySettings::escortStartsHour();
        $ends = SafetySettings::escortEndsHour();

        $start = $local->hour < $ends
            ? $local->subDay()->setTime($starts, 0)
            : $local->setTime($starts, 0);

        $end = $start->addDay()->setTime($ends, 0);

        $timezone = config('app.timezone');

        return [$start->setTimezone($timezone), $end->setTimezone($timezone)];
    }

    /**
     * @return Builder<EscortWindow>
     */
    public static function active(): Builder
    {
        return EscortWindow::query()
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }

    public static function activeFor(?string $corridorId): ?EscortWindow
    {
        if ($corridorId === null) {
            return null;
        }

        return self::active()->where('corridor_id', $corridorId)->oldest('starts_at')->first();
    }

    /**
     * The corridor ids under escort right now — for a page that badges many trips at once.
     *
     * @return array<int, string>
     */
    public static function activeCorridorIds(): array
    {
        return self::active()->distinct()->pluck('corridor_id')->all();
    }

    public static function armedCount(): int
    {
        return self::active()->distinct()->count('corridor_id');
    }

    /**
     * Runs counted against any window that overlaps tonight's — the number the desk reads the
     * morning after, and the one the dashboard tile and the escort page both show.
     */
    public static function tripsCoveredTonight(): int
    {
        [$start, $end] = self::tonight();

        return (int) EscortWindow::query()
            ->where('ends_at', '>', $start)
            ->where('starts_at', '<', $end)
            ->sum('trips_covered');
    }
}
