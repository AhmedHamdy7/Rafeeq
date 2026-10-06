<?php

use App\Domains\Admin\Models\AdminAction;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Notification\Models\Message;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Models\PaymentWebhook;
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
/*
 * Reveals one-sided ratings whose window has run out (Bible §9).
 *
 * 🔴 The half that stops silence from being a veto: without it, a passenger who never rates keeps
 * her driver's rating hidden for ever, and not writing a review becomes the quietest way to
 * suppress one.
 *
 * Hourly rather than daily, because the window is measured in days and a daily pass would make the
 * reveal land up to 24 hours late — long enough for somebody watching for it to notice the pattern
 * of WHEN things appear, which is a weaker version of the leak the design closes.
 */
Schedule::command('ratings:reveal-due')
    ->hourly()
    ->withoutOverlapping();

/*
 * "Last day to rate your trip" — one reminder per person per booking (per run for a driver), in
 * the final 24 hours of the window. Hourly so the reminder lands near the start of that last day
 * for everybody, whatever time their trip left; idempotent, so running it again sends nothing new.
 *
 * If it does not run: nobody is reminded, and somebody who never rates keeps the other side's
 * rating hidden until the window closes — the quiet veto the reminder exists to discourage.
 */
Schedule::command('ratings:send-reminders')
    ->hourly()
    ->withoutOverlapping();

/*
 * Saved ride requests past their expiry become `expired`. Matching already ignores them by date, so
 * nobody is matched to a stale request either way — what this fixes is the passenger's own list,
 * which otherwise shows a request as active for ever and never tells them to save a new one.
 */
Schedule::command('demands:expire')
    ->hourly()
    ->withoutOverlapping();

/*
 * 🔴 Cash collected on the day, recorded once the driver has confirmed who travelled and two hours
 * have passed undisputed (Bible §8.1, Master Plan §15.6). Writes the payment, the platform's fee as
 * the driver's debt, and marks the booking paid. Idempotent — one charge per booking by key.
 *
 * If it does not run: no cash trip is ever recorded as paid, drivers' fee debt never grows, and the
 * debt cap never stops anybody — the platform's whole income from cash trips goes unrecorded.
 */
Schedule::command('payments:settle-cash')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

/*
 * Pitfall #39: every driver's balance against the sum of their fee ledger. Reports drift to the
 * critical log and fails; never repairs it (see ReconcileDriverBalancesAction).
 */
Schedule::command('payments:reconcile-balances')
    ->dailyAt('03:30')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();

/*
 * Night escort (Master Plan: 9 PM–5 AM). Shortly before the window opens, so the live board shows
 * every corridor armed by the time the first night run starts; idempotent, so a second run (or a
 * retry after a failed deploy) arms nothing new. Off when `safety.night_escort_enabled` is 0.
 *
 * If it does not run: no corridor is under escort tonight unless somebody on the desk arms it by
 * hand — and the dashboard tile reads "0 corridors armed", which is how that gets noticed.
 */
Schedule::command('escort:arm-tonight')
    ->dailyAt('20:45')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();

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

/*
 * 🔒 Destroys private files past their `purge_after` date — identity documents, vehicle documents
 * and report evidence (ERD §18). The same kind of promise as the GPS purge above, and one the member
 * is shown: a report's evidence carries its destruction date in the app.
 *
 * Files under review and evidence on open cases are held (see PurgeExpiredFilesAction). If it does
 * not run: national IDs and photographs of bad moments are kept indefinitely, past a date the
 * member was told.
 */
Schedule::command('files:purge-expired')
    ->dailyAt('04:30')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();

/*
 * 🔒 Retention for the rows ERD §18 gives a lifetime: login codes (7 days), the inbox and push log
 * (90 days), trip chat (12 months), payment webhooks (90 days), the security log and the admin audit log (24 months each). The
 * models are listed by name because `model:prune` only looks in app/Models on its own, and every
 * model here lives under app/Domains — without the list it would run, find nothing, and succeed.
 *
 * If it does not run: everybody's trip chat is kept for ever, past a period the platform declared.
 */
Schedule::command('model:prune', ['--model' => [
    OtpChallenge::class,
    Notification::class,
    Message::class,
    PaymentWebhook::class,
    SecurityEvent::class,
    AdminAction::class,
]])
    ->dailyAt('04:45')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();
