<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;

/**
 * Keeping the numbers shown to waiting passengers as 1, 2, 3 rather than 2, 4, 7.
 *
 * 🔴 What this is, now that a freed seat is a race rather than a queue (Feature 03 v3).
 *
 * This used to live on `PromoteFromWaitlistAction`, which also promoted the first person in line.
 * Promotion is gone — a seat is offered to everybody eligible and the first acceptance wins — so
 * what survives is only the display order, and it is kept for two honest reasons:
 *
 *   1. the design shows it ("Position 1 of 2 · Est. seat within ~1 week"), and the product decision
 *      was to keep that as an **estimate** rather than delete it
 *   2. it decides who is TOLD first when a seat opens, which is the only advantage a position can
 *      confer once acceptance is first-come
 *
 * ⚠️ So a position is a forecast, not a claim. Nothing in the allocation path reads it, and no
 * client should tell somebody her position entitles her to the next seat.
 *
 * A gap is not cosmetic: the numbers are what a waiting passenger actually reads, so a hole makes
 * the queue look longer than it is and makes movement through it invisible.
 */
final class WaitlistPositions
{
    public static function renumber(string $commuteOfferId): void
    {
        $waiting = SeatRequest::query()
            ->where('commute_offer_id', $commuteOfferId)
            ->where('status', SeatRequestStatus::Waitlisted->value)
            ->orderBy('waitlist_position')
            ->orderBy('id')
            ->get();

        foreach ($waiting->values() as $index => $request) {
            $position = $index + 1;

            // Skipped when already right, so renumbering a settled queue writes nothing.
            if ($request->waitlist_position === $position) {
                continue;
            }

            $request->forceFill(['waitlist_position' => $position])->save();
        }
    }
}
