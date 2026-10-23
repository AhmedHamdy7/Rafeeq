<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteSchedule;
use Carbon\CarbonImmutable;

/**
 * What "today" means for one waiting-list membership (Feature 03 v5 §8).
 *
 * > *"`Available Today` / `Not Available Today` on standby management; reversible repeatedly.
 * > Turning unavailable excludes the member from today's automatic offers, preserves future
 * > recurring membership; returning available doesn't restore closed offers. No penalty."*
 *
 * 🔴 **The server decides which date she meant, never the client.** v5 gives her a toggle, not a
 * date picker, and a client-supplied date would let a phone with a wrong clock — or a wrong
 * timezone, which is the common case for somebody who has just flown home — exclude her from a
 * morning she fully intended to ride.
 *
 * 🔴 **And "today" is the commute's own local day, not the server's.** A commute stores the
 * timezone its wall-clock departures are written in, and `scheduled_trips.trip_date` is generated
 * in it. Reading the date in UTC instead would be wrong for three hours of every Cairo night: a
 * woman tapping "not available today" at 1 a.m. before a 7 a.m. run would have recorded YESTERDAY,
 * the flag would match nothing, and she would be offered the seat she had just declined — which is
 * the one failure she would read as the app ignoring her.
 */
final class StandbyAvailability
{
    /**
     * The local date that is "today" for this membership's commute.
     */
    public static function todayFor(SeatRequest $request): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezoneFor($request))->startOfDay();
    }

    /**
     * Is she available for this local trip date?
     *
     * Both sides are plain local dates — hers as she declared it, the day's as it was generated —
     * so this is a comparison and not a conversion. The SQL half of the same rule lives in
     * {@see WaitlistEligibility}, which is the one that actually guards the fan-out.
     */
    public static function isAvailableOn(SeatRequest $request, ?CarbonImmutable $tripDate): bool
    {
        if ($request->unavailable_on === null || $tripDate === null) {
            return true;
        }

        return ! $request->unavailable_on->isSameDay($tripDate);
    }

    /**
     * Is the day she declared still today — i.e. is the switch currently doing anything?
     *
     * This is what a client renders the toggle from. A stale date is reported as available rather
     * than being cleared on read: a read that writes would make the state depend on who looked at
     * it last, and the row is evidence of what she said on that day.
     */
    public static function isAvailableToday(SeatRequest $request): bool
    {
        /*
         * 🔴 Checked before anything is looked up, because `SeatRequestResource` reads this on
         * every row of every seat-request list — a driver's inbox is dozens of them. Resolving the
         * commute's timezone first would be a
         * query per row to answer a question the null already answers, which is the shape of an
         * N+1 that only shows up on a real inbox.
         */
        if ($request->unavailable_on === null) {
            return true;
        }

        return self::isAvailableOn($request, self::todayFor($request));
    }

    /**
     * The timezone this commute's days are written in, falling back to Cairo.
     *
     * A fallback rather than an exception: a commute with no schedule row cannot have generated a
     * single day, so there is nothing for the flag to exclude her from, and refusing the toggle
     * would be a 500 on a screen that works.
     */
    private static function timezoneFor(SeatRequest $request): string
    {
        $timezone = CommuteSchedule::query()
            ->where('commute_offer_id', $request->commute_offer_id)
            ->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Africa/Cairo';
    }
}
