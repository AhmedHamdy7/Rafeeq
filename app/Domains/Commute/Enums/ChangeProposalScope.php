<?php

namespace App\Domains\Commute\Enums;

/**
 * How far a proposed change reaches (Feature 03 v5: *"Support scope picker for `Only this
 * occurrence` vs `All future occurrences from [date]`"*).
 *
 * The two are different promises to different people, which is why the driver picks rather than the
 * system inferring: "I'm running late tomorrow" and "I'm moving this commute to 07:30 from now on"
 * need the same approval and affect a different number of mornings.
 */
enum ChangeProposalScope: string
{
    /** One generated day. Affects only the people confirmed on it. */
    case OnlyThisOccurrence = 'only_this_occurrence';

    /**
     * This weekday, from the chosen date onwards.
     *
     * 🔴 This weekday, not every weekday. v5 keeps each weekday independent — *"each weekday has
     * independent departure, promised arrival, price within route range, capacity"* — so a driver
     * moving her Tuesday departure has not said anything about her Thursday, and applying it to
     * both would change a morning nobody was asked about.
     */
    case AllFutureOnThisWeekday = 'all_future_on_this_weekday';

    /**
     * Whether applying this scope rewrites the commute's standing terms rather than one day.
     *
     * Read to decide whether the waiting list has to be cleared: v5 asks for that on an "Edit
     * Entire Commute", because somebody who queued for the old terms has not agreed to the new
     * ones.
     */
    public function rewritesTheTemplate(): bool
    {
        return $this === self::AllFutureOnThisWeekday;
    }
}
