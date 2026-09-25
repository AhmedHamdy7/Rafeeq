<?php

namespace App\Domains\Group\Actions;

use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Group\Enums\CommuteGroupStatus;
use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;

/**
 * Joining the group behind a commute.
 *
 * The group is what makes Rafeeq a commute-sharing product rather than a booking
 * engine: the same people travel together repeatedly, and that continuity is the
 * thing being built. It is created lazily, on the first person to join, because a
 * commute nobody has joined has no group to speak of.
 *
 * A trial rider joins as `trial`, not `member`. They are on one day, not
 * committed to a week, and the distinction is what lets the group show its real
 * regulars — and what stops one trial ride counting as a commitment nobody made.
 */
final readonly class AddMemberToGroupAction
{
    public function execute(SeatRequest $request, CommuteOffer $offer): GroupMember
    {
        $group = $this->groupFor($offer);

        $existing = GroupMember::query()
            ->where('commute_group_id', $group->id)
            ->where('user_id', $request->passenger_user_id)
            ->first();

        if ($existing !== null) {
            // Someone returning after a trial, or after leaving: the membership is
            // reactivated rather than duplicated. One membership per person per
            // group is a unique index, so a second row is impossible anyway — this
            // decides what "rejoining" means.
            $existing->forceFill([
                'role' => $this->roleFor($request)->value,
                'status' => GroupMemberStatus::Active->value,
                'committed_days_mask' => $request->requested_days_mask,
                'left_at' => null,
                'notice_given_at' => null,
            ])->save();

            return $existing;
        }

        $member = new GroupMember;

        $member->fill([
            'commute_group_id' => $group->id,
            'user_id' => $request->passenger_user_id,
            'role' => $this->roleFor($request)->value,
            'committed_days_mask' => $request->requested_days_mask,
        ]);

        $member->status = GroupMemberStatus::Active->value;
        $member->joined_at = now();

        $member->save();

        return $member;
    }

    /**
     * The group for this commute, created on first use — with the driver as its
     * first member, because a group whose driver is not in it would leave every
     * member list missing the one person who is always there.
     */
    public function groupFor(CommuteOffer $offer): CommuteGroup
    {
        $group = CommuteGroup::query()->where('commute_offer_id', $offer->id)->first();

        if ($group !== null) {
            return $group;
        }

        $group = new CommuteGroup;

        $group->fill([
            'commute_offer_id' => $offer->id,
            'name' => $this->nameFor($offer),
        ]);

        $group->status = CommuteGroupStatus::Active->value;
        $group->save();

        $driver = new GroupMember;

        $driver->fill([
            'commute_group_id' => $group->id,
            'user_id' => $offer->driver_profile_id,
            'role' => GroupMemberRole::Driver->value,
            'committed_days_mask' => $offer->schedule?->days_mask,
        ]);

        $driver->status = GroupMemberStatus::Active->value;
        $driver->joined_at = now();
        $driver->save();

        return $group;
    }

    private function roleFor(SeatRequest $request): GroupMemberRole
    {
        return $request->commitment === SeatRequestCommitment::Trial
            ? GroupMemberRole::Trial
            : GroupMemberRole::Member;
    }

    /**
     * Named after the journey, from the commute's own origin and destination
     * labels. A group called "Group 47" tells its members nothing.
     */
    private function nameFor(CommuteOffer $offer): string
    {
        $locations = $offer->locations;

        $origin = $locations->firstWhere('type', CommuteLocationType::Origin);
        $destination = $locations->firstWhere('type', CommuteLocationType::Destination);

        $from = $origin?->address ?? 'Origin';
        $to = $destination?->address ?? 'Destination';

        return mb_substr("{$from} → {$to}", 0, 150);
    }
}
