<?php

namespace App\Console\Commands;

use App\Domains\Booking\Actions\ExtendRecurringBookingsAction;
use App\Domains\Booking\Actions\RespondToSeatRequestAction;
use App\Domains\Group\Actions\FinaliseLeavesAction;
use Illuminate\Console\Command;

/**
 * The daily upkeep that keeps group membership honest.
 *
 * Three things, in this order, and the order is the reason they share a command:
 *
 *   1. requests that can no longer be answered are closed — a during-ride request past
 *      its three minutes, a one-day request whose day has passed (Feature 03 R-4).
 *      Nothing else expires: a request stays open until it is answered
 *   2. notices whose period has run out are completed — otherwise a member who gave
 *      notice a month ago is still on the driver's list
 *   3. committed members are seated on the days the generator has since created —
 *      otherwise recurring membership quietly stops working about a month in
 *
 * Step 2 must precede step 3. Reversed, somebody whose notice expired this morning
 * would be seated on next week's newly generated trips a moment before being
 * removed from the group — and those bookings would outlive the membership.
 *
 * ⚠️ Without a cron entry running `php artisan schedule:run`, none of this happens
 * and nothing anywhere reports an error. See DEPLOYMENT.md.
 */
final class RollMembershipsForward extends Command
{
    protected $signature = 'memberships:roll-forward';

    protected $description = 'Close requests whose day passed, complete leave notices, and seat committed members on newly generated days.';

    public function handle(
        FinaliseLeavesAction $finaliseLeaves,
        ExtendRecurringBookingsAction $extendBookings,
    ): int {
        // Belt and braces: `requests:expire` does this every minute; only during-ride requests
        // and one-day requests on a day that has passed are closed (Feature 03 R-4).
        $expired = RespondToSeatRequestAction::expireOverdue() + RespondToSeatRequestAction::closeDatePassed();
        $left = $finaliseLeaves->execute();
        $seated = $extendBookings->execute();

        $this->info("Expired {$expired} stale seat requests; completed {$left} leave notices; created {$seated} bookings for committed members.");

        return self::SUCCESS;
    }
}
