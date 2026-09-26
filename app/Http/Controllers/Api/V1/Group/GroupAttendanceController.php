<?php

namespace App\Http\Controllers\Api\V1\Group;

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Actions\DeclareAttendanceAction;
use App\Domains\Group\Enums\GroupAttendanceStatus;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\GroupAttendance;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Group\DeclareAttendanceRequest;
use App\Http\Resources\GroupAttendanceResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is travelling tomorrow.
 *
 * ⚠️ Declared intentions, not check-ins. This is what members said in advance so the
 * driver can plan; the actual boarding is `attendance` (Phase 9) and that is what
 * money depends on. The ERD warns about the confusion twice, so it is worth a third.
 *
 * 🔒 Everything is scoped through the caller's own membership. The whole group sees
 * the answers — that is the point of declaring them — but only the group.
 */
final class GroupAttendanceController extends Controller
{
    /**
     * GET /v1/groups/{group}/attendance?tripId=… — the answers for one day.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function index(Request $request, string $group): JsonResponse
    {
        $this->myMembership($request, $group);

        $tripId = $request->query('tripId');

        $declarations = GroupAttendance::query()
            ->where('commute_group_id', $group)
            ->when(is_string($tripId) && $tripId !== '', fn ($query) => $query->where('scheduled_trip_id', $tripId))
            ->with('user')
            ->orderBy('scheduled_trip_id')
            ->orderBy('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($declarations, GroupAttendanceResource::collection($declarations->items()));
    }

    /**
     * POST /v1/groups/{group}/attendance — declaring, or changing your answer.
     */
    #[ApiErrors(ErrorCode::AttendanceNotDeclarable, ErrorCode::NotFound)]
    public function store(
        DeclareAttendanceRequest $request,
        string $group,
        DeclareAttendanceAction $action,
    ): JsonResponse {
        $membership = $this->myMembership($request, $group);

        $trip = ScheduledTrip::query()->whereKey($request->validated('tripId'))->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $declaration = $action->execute(
            $membership,
            $trip,
            GroupAttendanceStatus::from($request->validated('status')),
        );

        return ApiResponse::success(new GroupAttendanceResource($declaration), status: 201);
    }

    /**
     * The caller's own membership of this group, or a 404.
     */
    private function myMembership(Request $request, string $groupId): GroupMember
    {
        return GroupMember::query()
            ->where('commute_group_id', $groupId)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', [
                GroupMemberStatus::Active->value,
                // Somebody serving out a notice period is still travelling, so they
                // still answer for the days they are here.
                GroupMemberStatus::NoticeGiven->value,
            ])
            ->with('commuteGroup')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
