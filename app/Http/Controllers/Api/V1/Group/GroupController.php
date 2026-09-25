<?php

namespace App\Http\Controllers\Api\V1\Group;

use App\Domains\Group\Actions\LeaveGroupAction;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\CommuteGroupResource;
use App\Http\Resources\GroupMemberResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The group behind a commute: who is in it, what it commits to, and leaving it.
 *
 * 🔒 Every route here is reachable only by a member of the group in question, the
 * driver included, and a miss is a 404. That is not a formality: a group's member
 * list is a list of real people's first names, trust levels and the days they
 * reliably travel — which is to say when they are and are not at home. There is no
 * endpoint at any access level that shows it to somebody outside the group.
 *
 * A former member keeps nothing: once a membership is `left` or `removed`, the
 * lookups below stop matching and the group becomes as invisible as it was before
 * they joined.
 */
final class GroupController extends Controller
{
    /**
     * GET /v1/groups — the groups the caller travels with.
     */
    public function index(Request $request): JsonResponse
    {
        $groups = CommuteGroup::query()
            ->whereIn('id', $this->myMembershipScope($request)->select('commute_group_id'))
            ->with('commuteOffer.schedule', 'commuteOffer.locations')
            ->latest('created_at')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(CommuteGroupResource::collection($groups));
    }

    /**
     * GET /v1/groups/{group} — the overview, including the rules everybody agreed to.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function show(Request $request, string $group): JsonResponse
    {
        $found = $this->mine($request, $group);

        $found->load([
            'commuteOffer.schedule',
            'commuteOffer.locations',
            // The rules a member accepted when they joined. Shown here because the
            // group screen is where somebody looks them up months later.
            'commuteOffer.rules',
            // Just the next day, for the seats-free figure. Constrained in the
            // eager load rather than counted in the resource, so this stays one
            // query whatever the group's history.
            'commuteOffer.scheduledTrips' => fn ($query) => $query
                ->where('departure_at', '>', now())
                ->orderBy('trip_date')
                ->limit(1),
            // `members.commuteGroup` because the member resource reads the group's
            // notice period to say when a leaving member's last day is.
            'members.commuteGroup',
        ]);

        return ApiResponse::success(new CommuteGroupResource($found));
    }

    /**
     * GET /v1/groups/{group}/members — 🔒 public first names and trust levels only.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function members(Request $request, string $group): JsonResponse
    {
        $this->mine($request, $group);

        $members = GroupMember::query()
            ->where('commute_group_id', $group)
            // People who have left are not in the group. Somebody serving out a
            // notice period still is — they are travelling tomorrow.
            ->whereIn('status', [
                GroupMemberStatus::Active->value,
                GroupMemberStatus::NoticeGiven->value,
            ])
            /*
             * `commuteGroup` as well as `user`: the resource reads the group's own
             * `notice_period_days` to say when a leaving member's last day is, and
             * without this that is one query per member.
             */
            ->with('user', 'commuteGroup')
            // The driver first, then by how long they have been part of it.
            ->orderByRaw("CASE WHEN role = 'driver' THEN 0 ELSE 1 END")
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(GroupMemberResource::collection($members));
    }

    /**
     * POST /v1/groups/{group}/leave — giving notice.
     *
     * The seats beyond the notice period are released immediately, so they can be
     * filled while there is still time; the days inside it are kept, because the
     * member is still travelling them.
     */
    #[ApiErrors(
        ErrorCode::GroupDriverCannotLeave,
        ErrorCode::GroupNoticeAlreadyGiven,
        ErrorCode::GroupNotActive,
        ErrorCode::NotFound,
    )]
    public function leave(Request $request, string $group, LeaveGroupAction $action): JsonResponse
    {
        $membership = GroupMember::query()
            ->where('commute_group_id', $group)
            ->where('user_id', $request->user()->id)
            ->with('commuteGroup')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $left = $action->giveNotice($membership, $request->input('reason'));

        return ApiResponse::success(new GroupMemberResource($left));
    }

    /**
     * A group the caller is actually in. 404 for every other id, existing or not.
     */
    private function mine(Request $request, string $groupId): CommuteGroup
    {
        return CommuteGroup::query()
            ->whereKey($groupId)
            ->whereIn('id', $this->myMembershipScope($request)->select('commute_group_id'))
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    /**
     * @return Builder<GroupMember>
     */
    private function myMembershipScope(Request $request)
    {
        return GroupMember::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', [
                GroupMemberStatus::Active->value,
                GroupMemberStatus::NoticeGiven->value,
            ]);
    }
}
