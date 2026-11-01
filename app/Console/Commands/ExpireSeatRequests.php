<?php

namespace App\Console\Commands;

use App\Domains\Booking\Actions\OfferSeatToWaitlistAction;
use App\Domains\Booking\Actions\RespondToSeatRequestAction;
use App\Domains\Trip\Support\PickupReachability;
use Illuminate\Console\Command;

/**
 * Closes seat requests that can no longer be answered: a during-ride request past its three minutes
 * (Feature 03 v5 §416), and a one-day request whose day has gone by (R-4, `closed_date_passed`).
 *
 * Nothing else is closed here. Requests do not expire any more (R-4, BOOK-36): the 48-hour window
 * is gone, and only the during-ride one carries an `expires_at`.
 *
 * 🔴 **Every minute, because of the three-minute one.** The same sweep already ran with the daily
 * membership roll, which was the right cadence while every window was forty-eight hours: a request
 * sitting `pending` a few hours past its expiry was invisible to anybody, since nothing could be
 * done with it.
 *
 * A request to join a run that is already moving expires in three minutes, and the driver is looking
 * at it on a sheet right now. A status that caught up tomorrow would show her a live question that
 * had already timed out, and she would tap it.
 *
 * ⚠️ This is display truth and not the rule. Approval refuses an expired request on its own, by
 * reading `expires_at` rather than trusting the status — see `ApproveSeatRequestAction`. A sweep
 * that failed to run would make the sheet wrong; it could never make a timed-out request
 * approvable.
 */
final class ExpireSeatRequests extends Command
{
    protected $signature = 'requests:expire';

    protected $description = 'Close during-ride requests past their three minutes, and one-day requests whose day has passed';

    public function handle(OfferSeatToWaitlistAction $offers, PickupReachability $reachability): int
    {
        $expired = RespondToSeatRequestAction::expireOverdue();
        $datePassed = RespondToSeatRequestAction::closeDatePassed();

        /*
         * 🔴 And offers on runs that have driven past the person they were made to (v5
         * seat-allocation point 6, required test 4). The same job as the line above — closing a
         * question that has stopped being answerable — on the same cadence, because both are
         * things a client is looking at right now.
         *
         * Closed `INELIGIBLE` rather than `CLOSED`: the seat still exists, she simply stopped
         * qualifying for it, and a woman reading her own history should be able to tell "the car
         * went past my street" from "the day was cancelled".
         */
        $unreachable = $offers->closeUnreachable($reachability);

        $this->info("Expired {$expired} seat request(s); closed {$datePassed} whose day passed; closed {$unreachable} unreachable offer(s).");

        return self::SUCCESS;
    }
}
