<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Every recurring job the platform depends on. A job listed here needs a cron
| entry running `php artisan schedule:run` every minute on the server, or none
| of it happens — which is worth stating because a commute that stops being
| bookable a month after it was created is a silent failure nobody notices
| until a passenger cannot book next month.
|
*/

/*
 * Rolls the scheduled-trip horizon forward and archives commutes whose end date
 * has passed. Early morning, in Cairo time, so a day's trips exist before anyone
 * is awake to look for them.
 *
 * `withoutOverlapping` because a slow run must not be joined by the next one:
 * generation is idempotent, but two passes over the same offers is wasted work.
 */
Schedule::command('commutes:generate-trips')
    ->dailyAt('03:00')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();

/*
 * The membership side of the same daily roll: stale requests expired, leave
 * notices completed, and committed members seated on the days the generator has
 * just created.
 *
 * Deliberately AFTER trip generation. Reversed, it would seat members on
 * yesterday's horizon and the newest day would stay empty until tomorrow — which
 * is the failure it exists to prevent, one day later.
 */
Schedule::command('memberships:roll-forward')
    ->dailyAt('03:30')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();

/*
 * Writes the buffered GPS points down (pitfall #46).
 *
 * Every thirty seconds, because that is the interval the Bible names and because the window
 * is also the exposure: whatever is buffered when the process dies is lost, and a trail with
 * a thirty-second hole in it is still evidence while a trail with a ten-minute hole is not.
 *
 * `withoutOverlapping` because two flushes racing over the same buffer would each take half
 * of it — `Cache::pull` is read-and-clear, so the loser writes nothing and reports success.
 */
Schedule::command('trips:flush-locations')
    ->everyThirtySeconds()
    ->withoutOverlapping();

/*
 * 🔒 Deletes GPS trails past their retention date (ERD §23.4: 90 days).
 *
 * Not housekeeping. `trip_locations` is a record of where real people were, kept for one
 * purpose — a dispute with no other evidence — and past ninety days that purpose is gone.
 * A record with no purpose left is something to be subpoenaed, breached or repurposed, and
 * none of those were consented to. Keeping this running is part of what makes collecting
 * the data defensible at all.
 *
 * Early morning, when the tables are quiet: the delete walks tens of millions of rows.
 */
Schedule::command('trips:purge-locations')
    ->dailyAt('04:00')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();
