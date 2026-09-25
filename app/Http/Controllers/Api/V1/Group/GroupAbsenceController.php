<?php

namespace App\Http\Controllers\Api\V1\Group;

use App\Domains\Group\Actions\PlanAbsenceAction;
use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\GroupAbsence;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Group\PlanAbsenceRequest;
use App\Http\Resources\GroupAbsenceResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Planned absences: "I'm away from the 20th to the 27th."
 *
 * 🔒 The whole group sees them, which is the point — a driver planning Sunday needs
 * to know who will not be there, and so do the other passengers. Only the group,
 * and only through the caller's own membership.
 *
 * Deleting is restricted to the person whose absence it is. A driver cannot cancel
 * somebody else's plans, even in their own group: an absence is a statement about
 * where a person will be, and only they can withdraw it.
 */
final class GroupAbsenceController extends Controller
{
    /**
     * GET /v1/groups/{group}/absences — everybody's, so the group can plan.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function index(Request $request, string $group): JsonResponse
    {
        $this->myMembership($request, $group);

        $absences = GroupAbsence::query()
            ->where('commute_group_id', $group)
            // Past absences are history nobody is planning around.
            ->where('to_date', '>=', now()->toDateString())
            ->with('user')
            ->orderBy('from_date')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(GroupAbsenceResource::collection($absences));
    }

    /**
     * POST /v1/groups/{group}/absences.
     *
     * With `releasesSeat`, the bookings in that range are actually cancelled and the
     * seats offered to whoever is waiting — which is why it defaults to false.
     */
    #[ApiErrors(
        ErrorCode::AbsenceOverlaps,
        ErrorCode::AbsenceTooLong,
        ErrorCode::NotFound,
    )]
    public function store(PlanAbsenceRequest $request, string $group, PlanAbsenceAction $action): JsonResponse
    {
        $membership = $this->myMembership($request, $group);

        if ($membership->role === GroupMemberRole::Driver) {
            /*
             * A driver is not absent from their own commute — if they are not
             * driving, the trip does not run. Cancelling the day is the operation
             * they want, and it belongs to the trip lifecycle where every passenger
             * on it gets told.
             */
            throw DomainException::of(ErrorCode::GroupDriverCannotLeave);
        }

        return ApiResponse::success(
            new GroupAbsenceResource($action->execute($membership, $request->validated())),
            status: 201,
        );
    }

    /**
     * DELETE /v1/groups/{group}/absences/{absence} — calling it off.
     *
     * ⚠️ This does NOT restore bookings a released absence cancelled. Those seats were
     * given back and may already belong to somebody else; undoing an absence restores
     * the member's plans, not other people's.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function destroy(Request $request, string $group, string $absence, PlanAbsenceAction $action): JsonResponse
    {
        $this->myMembership($request, $group);

        $mine = GroupAbsence::query()
            ->where('commute_group_id', $group)
            // Theirs, not the group's: see the class note.
            ->where('user_id', $request->user()->id)
            ->whereKey($absence)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $action->cancel($mine);

        // 200 with the envelope, like every other delete here: a bodyless 204 would
        // be the one response the client has to special-case.
        return ApiResponse::success(['deleted' => true]);
    }

    private function myMembership(Request $request, string $groupId): GroupMember
    {
        return GroupMember::query()
            ->where('commute_group_id', $groupId)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', [
                GroupMemberStatus::Active->value,
                GroupMemberStatus::NoticeGiven->value,
            ])
            ->with('commuteGroup')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
