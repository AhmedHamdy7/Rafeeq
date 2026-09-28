<?php

namespace App\Domains\Trip\Enums;

/**
 * How the wait ended (Bible §7: "five minutes · she extends or she leaves").
 *
 * 🔴 The three values are three different stories, and the difference between the last two
 * is the whole reason this is an enum and not a boolean:
 *
 * - `arrived` — she came. The run carries on.
 * - `no_show` — the grace ran out and she still was not there. The driver waited the time
 *   the platform promised and then left.
 * - `driver_left` — the driver departed BEFORE the grace ran out.
 *
 * Collapsing those last two into "no-show" would hide the only fact a dispute turns on. A
 * passenger marked absent after ninety seconds of a five-minute grace was not given the
 * time she was promised, and with one value there would be no way to tell that morning
 * apart from one where she simply never came.
 */
enum WaitTimerOutcome: string
{
    case Arrived = 'arrived';
    case NoShow = 'no_show';
    case DriverLeft = 'driver_left';
}
