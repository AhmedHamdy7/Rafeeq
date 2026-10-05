<?php

namespace App\Domains\Group\Actions;

use App\Domains\Booking\Actions\CancelBookingAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Leaving the group, with the notice the group is owed.
 *
 * `commute_groups.notice_period_days` exists because a commute is a standing
 * arrangement, not a booking. A driver who planned their month around four
 * passengers should not find out on Sunday night that one of them is gone — so
 * leaving is a notice, not a switch.
 *
 * What happens immediately:
 *
 *   - the membership becomes `notice_given`, and the date it ends is fixed
 *   - bookings AFTER that date are cancelled now, so the seats go back on offer
 *     while there is still time for somebody to take them
 *   - bookings INSIDE the notice period are kept: the member is still travelling,
 *     and cancelling them would be the group leaving them rather than the reverse
 *
 * What happens on the date itself is {@see FinaliseLeavesAction}, run by a daily
 * command — a notice with nothing to complete it would leave members in
 * `notice_given` forever.
 *
 * A driver cannot leave their own group. It is not a permission problem: the group
 * exists because their commute does, and "everyone travels except the person
 * driving" is not a state to represent. Pausing or archiving the commute is the
 * operation they actually want.
 */
final readonly class LeaveGroupAction
{
    public function __construct(private CancelBookingAction $cancel) {}

    public function giveNotice(GroupMember $member, ?string $reason = null): GroupMember
    {
        if ($member->role === GroupMemberRole::Driver) {
            throw DomainException::of(ErrorCode::GroupDriverCannotLeave);
        }

        if ($member->status === GroupMemberStatus::NoticeGiven) {
            throw DomainException::of(ErrorCode::GroupNoticeAlreadyGiven, fields: [
                'leavesOn' => [self::leavesOn($member)->toDateString()],
            ]);
        }

        if (! $member->isActive()) {
            throw DomainException::of(ErrorCode::GroupNotActive);
        }

        return DB::transaction(function () use ($member, $reason): GroupMember {
            $member->forceFill([
                'status' => GroupMemberStatus::NoticeGiven->value,
                'notice_given_at' => now(),
                'removal_reason' => $reason,
            ])->save();

            $this->cancelBookingsAfter($member, self::leavesOn($member), $reason);

            return $member;
        });
    }

    /**
     * The day the membership ends: the notice period counted from the moment notice
     * was given, using the group's own figure rather than a fixed one — a school run
     * and a work commute do not owe the same warning.
     */
    public static function leavesOn(GroupMember $member): CarbonInterface
    {
        $given = $member->notice_given_at ?? now();

        return $given->copy()
            ->addDays($member->commuteGroup->notice_period_days)
            ->endOfDay();
    }

    /**
     * Releases the seats the member will no longer be using, through the ordinary
     * cancellation path so the lock, the audit event and the waiting list are all
     * handled the one way they are handled everywhere else.
     */
    private function cancelBookingsAfter(GroupMember $member, CarbonInterface $leavesOn, ?string $reason): void
    {
        $bookings = Booking::query()
            ->where('passenger_user_id', $member->user_id)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereIn('scheduled_trip_id', ScheduledTrip::query()
                ->where('commute_offer_id', $member->commuteGroup->commute_offer_id)
                ->where('departure_at', '>', $leavesOn)
                ->select('id'))
            ->get();

        foreach ($bookings as $booking) {
            $this->cancel->byPassenger($booking, $reason ?? __('commute.group.left'), notify: false);
        }
    }
}
