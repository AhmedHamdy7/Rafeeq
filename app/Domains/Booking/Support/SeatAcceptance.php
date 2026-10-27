<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Models\Booking;

/**
 * What accepting a seat actually did (Feature 03 v5 §231).
 *
 * 🔴 Exists because one of these operations can do something a `Booking` cannot describe. A plain
 * acceptance produces a seat and nothing else, and the booking says it all. A switch with
 * `all_future_weekdays` scope **releases every future day she held on the old commute** — v5 asks
 * for that policy to be stated rather than left to be inferred, and a woman who has just given up
 * a month of mornings should be told which ones, by the call that did it, not by comparing two
 * screens afterwards.
 *
 * So the Action returns what happened instead of only what she now holds.
 */
final readonly class SeatAcceptance
{
    /**
     * @param  list<string>  $releasedDates  the local dates of the old bookings this acceptance
     *                                       gave up, newest intent first; empty for a plain
     *                                       acceptance or a single-date switch of the target day
     *                                       alone
     */
    public function __construct(
        public Booking $booking,
        public array $releasedDates = [],
    ) {}
}
