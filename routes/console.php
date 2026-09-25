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
