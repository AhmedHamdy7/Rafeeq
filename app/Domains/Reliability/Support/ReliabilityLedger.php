<?php

namespace App\Domains\Reliability\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Enums\ReliabilityRole;
use App\Domains\Reliability\Models\ReliabilityEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Writing somebody's reliability record, and reading the score off it (Feature 03 v5).
 *
 * 🔴 **The score is derived on every read, never stored.** `RecomputeRatingStatsAction` states the
 * principle — a figure maintained incrementally drifts the first time a job runs twice — and it
 * matters more here than anywhere else in the product: v5 routes a low score to a temporary
 * suspension and to a permanent ban, so the number has to be reconstructible line by line in front
 * of whoever is making that decision.
 *
 * 🔴 **A hundred down to nothing, and lower is worse.** v5 speaks of "low Reliability" triggering
 * review, so the direction is fixed by the document. Somebody with no history scores 100 — which is
 * the same choice `PersonSummary` makes for an unrated driver, and for the same reason: a new
 * member has not failed anybody, and starting her at zero would make the number say she had.
 *
 * ⚠️ **The weights are placeholders pending a product decision.** v5 defers the fee brackets
 * outright and says nothing about score weights. What is defensible is their proportion to each
 * other, not their absolute size — see {@see ReliabilityEventKind::weight()}.
 */
final class ReliabilityLedger
{
    /**
     * Somebody with nothing on their record.
     *
     * Not a config value: it is the top of the scale rather than a policy, and a configurable
     * ceiling would make two deployments' scores incomparable.
     */
    public const int PERFECT = 100;

    /**
     * Record something that happened.
     *
     * 🔴 Idempotent on (user, kind, booking) where there is a booking, because the paths that call
     * this are retried: a cancellation processed twice — an overlapping sweep, a replayed job —
     * must not put two marks on one person for one morning. Where there is no booking the caller
     * is responsible for not calling twice, which is documented at each site.
     */
    public static function record(
        string $userId,
        ReliabilityEventKind $kind,
        ?Booking $booking = null,
        ?string $scheduledTripId = null,
        ?string $commuteOfferId = null,
        ?CarbonImmutable $occurredAt = null,
    ): ?ReliabilityEvent {
        if ($booking !== null && self::alreadyRecorded($userId, $kind, (string) $booking->getKey())) {
            return null;
        }

        $event = new ReliabilityEvent;

        $event->forceFill([
            'id' => (string) Str::ulid(),
            'user_id' => $userId,
            'role' => $kind->role()->value,
            'kind' => $kind->value,
            /*
             * Snapshotted, never re-read at score time: a weight change must not rewrite a record
             * that has already been reviewed and acted on.
             */
            'weight' => $kind->weight(),
            'occurred_at' => $occurredAt ?? CarbonImmutable::now(),
            'booking_id' => $booking?->getKey(),
            'scheduled_trip_id' => $scheduledTripId ?? $booking?->scheduled_trip_id,
            'commute_offer_id' => $commuteOfferId,
        ])->save();

        return $event;
    }

    /**
     * Her standing in one role, from 100 down.
     */
    public static function scoreFor(string $userId, ReliabilityRole $role): int
    {
        $lost = (int) ReliabilityEvent::query()
            ->counting()
            ->where('user_id', $userId)
            ->where('role', $role->value)
            ->where('occurred_at', '>=', self::windowOpensAt())
            ->sum('weight');

        // Never below zero: a score of -40 would sort and read as though it were a different kind
        // of number, and "as bad as it gets" is 0.
        return max(0, self::PERFECT - $lost);
    }

    /**
     * The events behind a score, newest first — for the monthly review v5 asks for.
     *
     * 🔴 Returned alongside the number wherever the number is shown to somebody who can act on it.
     * A bare score invites a decision nobody can check; the line items are what make a suspension
     * reviewable, and what a successful appeal is argued against.
     *
     * Reversed events are INCLUDED here, marked as such, which is the one place they must appear:
     * that she appealed and won is exactly what a second appeal and a reinstatement turn on.
     *
     * @return Collection<int, ReliabilityEvent>
     */
    public static function historyFor(string $userId, ReliabilityRole $role, int $limit = 50)
    {
        return ReliabilityEvent::query()
            ->where('user_id', $userId)
            ->where('role', $role->value)
            ->latest('occurred_at')
            // Standard #42: a tiebreak, so two events recorded in the same second do not swap
            // between two reads of the same record.
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * When the window the score looks at opens.
     *
     * ⚠️ The ROWS are permanent — v5: "Score/history persist after reinstatement" — and only the
     * score is windowed. A cancellation from two years ago is part of her history and is not part
     * of whether she can be relied on this month.
     */
    public static function windowOpensAt(): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays((int) config('rafeeq.reliability.window_days'));
    }

    /**
     * How close to departure a passenger's own cancellation starts to cost her.
     */
    public static function lateCancellationHours(): int
    {
        return (int) config('rafeeq.reliability.late_cancellation_hours');
    }

    private static function alreadyRecorded(string $userId, ReliabilityEventKind $kind, string $bookingId): bool
    {
        return ReliabilityEvent::query()
            ->where('user_id', $userId)
            ->where('kind', $kind->value)
            ->where('booking_id', $bookingId)
            ->exists();
    }
}
