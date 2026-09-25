<?php

namespace App\Domains\Group\Actions;

use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Enums\GroupAttendanceStatus;
use App\Domains\Group\Models\GroupAttendance;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * "I'm coming tomorrow" / "I'm away tomorrow".
 *
 * ⚠️ This is the DECLARED intention, not the actual check-in. The ERD is emphatic
 * about not confusing the two: `group_attendance` helps a driver plan their
 * morning, while `attendance` (ERD group ⑩, Phase 9) is what actually happened and
 * what drives billing. Nothing here affects money or seat counts.
 *
 * One declaration per person per day, replaced when they change their mind —
 * enforced by a unique index on (group, trip, user) and honoured here by updating
 * the existing row rather than adding a second one.
 *
 * The cutoff is the point of the whole feature. After it, the driver is already
 * planning around the answer they were given, so changing it silently would make
 * the declaration worthless — which is why "I'm away" two minutes before departure
 * is refused rather than recorded.
 */
final readonly class DeclareAttendanceAction
{
    public function execute(GroupMember $member, ScheduledTrip $trip, GroupAttendanceStatus $status): GroupAttendance
    {
        if ($trip->commute_offer_id !== $member->commuteGroup->commute_offer_id) {
            // A day that belongs to a different commute. 404-shaped: the id must
            // not be usable to discover somebody else's trips.
            throw DomainException::of(ErrorCode::NotFound);
        }

        $this->assertStillDeclarable($trip);

        $declaration = GroupAttendance::query()
            ->where('commute_group_id', $member->commute_group_id)
            ->where('scheduled_trip_id', $trip->id)
            ->where('user_id', $member->user_id)
            ->first();

        if ($declaration === null) {
            $declaration = new GroupAttendance;

            // The three keys are set explicitly rather than through `fill()` from
            // input: a declaration must never be able to name somebody else.
            $declaration->commute_group_id = $member->commute_group_id;
            $declaration->scheduled_trip_id = $trip->id;
            $declaration->user_id = $member->user_id;
        }

        $declaration->status = $status->value;
        $declaration->marked_at = now();
        $declaration->save();

        return $declaration;
    }

    private function assertStillDeclarable(ScheduledTrip $trip): void
    {
        if ($trip->status !== ScheduledTripStatus::Scheduled) {
            throw DomainException::of(ErrorCode::AttendanceNotDeclarable);
        }

        $cutoff = $trip->departure_at->subHours((int) config('rafeeq.group.attendance_cutoff_hours'));

        if ($cutoff->isPast()) {
            throw DomainException::of(ErrorCode::AttendanceNotDeclarable, fields: [
                'cutoffAt' => [$cutoff->toIso8601String()],
            ]);
        }
    }
}
