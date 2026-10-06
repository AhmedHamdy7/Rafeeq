<?php

use App\Domains\Matching\Jobs\NotifyMatchingDemands;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Guards the work that happens when nobody is looking.
 *
 * This file exists because of the worst failure mode in the system: a green test
 * suite over broken behaviour. Tests run with `QUEUE_CONNECTION=sync`, so a queued
 * job executes inline and every assertion about its EFFECT passes. In development
 * and production the connection is `database`, so the same job goes to a table and
 * sits there until a worker picks it up — and if no worker runs, nothing happens
 * and nothing complains.
 *
 * A passenger who saved a request would simply never be told that a matching
 * commute appeared. A recurring commute would stop being bookable a month after it
 * was published, because the horizon was never rolled forward.
 *
 * These tests cannot start a worker. What they CAN do is pin down the two things
 * that would otherwise be lost quietly — that the schedule still contains the
 * commands the platform depends on, and that the queued work really is queued and
 * therefore really does need a process. The processes themselves are listed in
 * DEPLOYMENT.md.
 */
it('registers every scheduled command the platform depends on', function (string $command) {
    $registered = [];

    foreach (app(Schedule::class)->events() as $event) {
        $registered[] = $event->command ?? $event->description ?? '';
    }

    $found = false;

    foreach ($registered as $entry) {
        if (str_contains((string) $entry, $command)) {
            $found = true;

            break;
        }
    }

    expect($found)->toBeTrue(
        "The scheduled command [{$command}] is not registered. Without it that work "
        .'simply never happens, and nothing reports the absence.'
    );
})->with([
    // Rolls the scheduled-trip horizon forward. Without it a recurring commute
    // stops being bookable roughly a month after it was published.
    'commutes:generate-trips',

    /*
     * The membership side of the same roll. Without it, three things rot quietly:
     * requests nobody answered hold the passenger's one open slot forever, members
     * who gave notice stay on the driver's list indefinitely, and — worst — a
     * committed member stops being booked once the horizon they joined under runs
     * out. They believe they are a member, their group screen agrees, and one
     * morning the car does not stop for them.
     */
    'memberships:roll-forward',

    // The last-day rating nudge. Without it, somebody who never rates keeps the other side's
    // rating hidden until the window closes, and nobody is told the window is closing.
    'ratings:send-reminders',

    // Night escort. Without it no corridor is watched at night unless somebody remembers to arm it.
    'escort:arm-tonight',

    // Retention for identity documents and report evidence. Without it the dates shown to members
    // are never honoured.
    'files:purge-expired',

    // Saved ride requests past their expiry. Without it the passenger's list says "active" for ever.
    'demands:expire',

    // Retention for login codes, notifications, chat, webhooks and the security log (ERD §18).
    'model:prune',

    // Cash collected on the day. Without it nothing is ever recorded as paid and drivers' fee debt
    // never grows, so the debt cap never applies.
    'payments:settle-cash',

    // The fee ledger against its projection (pitfall #39).
    'payments:reconcile-balances',
]);

/**
 * The order is load-bearing, not tidiness.
 *
 * Reversed, somebody whose notice expired this morning would be seated on next week's
 * newly generated trips a moment before being removed from the group — and those
 * bookings would outlive the membership that justified them.
 */
it('rolls memberships forward only after the trips they need exist', function () {
    $events = collect(app(Schedule::class)->events());

    $generate = $events->first(fn ($event) => str_contains((string) $event->command, 'commutes:generate-trips'));
    $memberships = $events->first(fn ($event) => str_contains((string) $event->command, 'memberships:roll-forward'));

    expect($generate)->not->toBeNull()
        ->and($memberships)->not->toBeNull()
        ->and($memberships->expression)->toMatch('/^\d+ \d+ \* \* \*$/');

    // Compared as "minutes past midnight" from the cron expression, because that is
    // the only thing that decides which runs first.
    $minutesOf = function (string $expression): int {
        [$minute, $hour] = explode(' ', $expression);

        return ((int) $hour * 60) + (int) $minute;
    };

    expect($minutesOf($memberships->expression))
        ->toBeGreaterThan($minutesOf($generate->expression));
});

it('schedules the trip generator at a fixed daily time, not on every run', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'commutes:generate-trips'));

    expect($event)->not->toBeNull()
        // Daily at a fixed hour. A cadence of `* * * * *` would mean the generator
        // walks every published commute on the platform every minute.
        ->and($event->expression)->toMatch('/^\d+ \d+ \* \* \*$/')
        // Overlap protection, because generation is idempotent but a second pass
        // over the same offers is wasted work.
        ->and($event->withoutOverlapping)->toBeTrue();
});

/**
 * If this ever stops being queued, publishing a commute starts running the whole
 * search engine once per waiting demand inside the driver's request — and their
 * publish could fail because the matching did.
 */
it('keeps the demand matching off the request', function () {
    expect(new NotifyMatchingDemands('01j'))->toBeInstanceOf(ShouldQueue::class);
});

it('runs the suite on a synchronous queue, which is why a worker is still needed', function () {
    // Stated here so nobody reads a passing test about a job's EFFECT as evidence
    // that the deployed system does that work. It does not: `sync` is why the
    // effect is observable at all in a test.
    expect(config('queue.default'))->toBe('sync');
});

/*
 * NOT asserted here, deliberately: that the deployed queue connection is not
 * `sync`, and that a worker and a cron entry exist.
 *
 * `phpunit.xml` overrides QUEUE_CONNECTION for the whole suite, so a test cannot
 * see the real value — and a real deployment takes it from the environment, not
 * from a file in the repository. A test that read `.env` would be asserting
 * something about this machine rather than about the product, which is the same
 * kind of false comfort this file was written to prevent.
 *
 * The processes are listed in DEPLOYMENT.md, and their absence is the one failure
 * in this system that is silent. That is a deployment checklist item, not
 * something a test can honestly cover.
 */
