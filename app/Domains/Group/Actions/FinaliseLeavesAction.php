<?php

namespace App\Domains\Group\Actions;

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\GroupMember;

/**
 * Completes the notices whose period has run out.
 *
 * Without this, {@see LeaveGroupAction} would leave people in `notice_given`
 * indefinitely: a member who gave notice a month ago would still be counted among
 * the group's members, still appear on the driver's list, and still be nominally
 * committed to days they told everybody they were leaving. The notice period is a
 * promise about a date, and a date needs something to arrive.
 *
 * The seats were already released when notice was given, so there is nothing to
 * refund or cancel here — this is the bookkeeping half.
 */
final readonly class FinaliseLeavesAction
{
    /**
     * @return int how many memberships were closed
     */
    public function execute(): int
    {
        $closed = 0;

        GroupMember::query()
            ->where('status', GroupMemberStatus::NoticeGiven->value)
            ->whereNotNull('notice_given_at')
            ->with('commuteGroup')
            ->chunkById(200, function ($members) use (&$closed): void {
                foreach ($members as $member) {
                    /*
                     * The comparison is made in PHP rather than SQL because the
                     * period is the GROUP's, not a constant: a SQL predicate would
                     * have to join and do date arithmetic per row on a column the
                     * group owns. The set being walked is only members who have
                     * given notice, which is small.
                     */
                    if (LeaveGroupAction::leavesOn($member)->isFuture()) {
                        continue;
                    }

                    $member->forceFill([
                        'status' => GroupMemberStatus::Left->value,
                        'left_at' => now(),
                    ])->save();

                    $this->closeTheirApprovedRequest($member);

                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * 🔴 The approval that created this membership is finished with, and saying so is
     * what lets the person come back later.
     *
     * "One open request per passenger per commute" counts `approved` as open, and it
     * is enforced by a generated unique column rather than only in code. Left as it
     * is, the spent approval holds that slot for good: somebody who left in October
     * and wants to rejoin in February gets a 409 that neither they nor the driver can
     * clear. See {@see SeatRequestStatus::Ended}.
     */
    private function closeTheirApprovedRequest(GroupMember $member): void
    {
        SeatRequest::query()
            ->where('passenger_user_id', $member->user_id)
            ->where('commute_offer_id', $member->commuteGroup->commute_offer_id)
            ->where('status', SeatRequestStatus::Approved->value)
            ->update(['status' => SeatRequestStatus::Ended->value]);
    }
}
