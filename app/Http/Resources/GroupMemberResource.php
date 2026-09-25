<?php

namespace App\Http\Resources;

use App\Domains\Group\Actions\LeaveGroupAction;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\GroupMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One member of a commute group, as the other members see them.
 *
 * 🔒 This is the privacy boundary the Master Plan means by "members (بخصوصية)".
 * People who travel together every morning do need to recognise each other and to
 * know what has been verified — that is most of what makes the group safe. They do
 * not need a full name, a phone number, an email, or a gender, and none of those
 * appear here at any access level.
 *
 * A phone number in particular: the platform's own messaging (Phase 12) is how
 * members reach each other, precisely so that leaving a group does not hand
 * everybody's number to four strangers permanently.
 *
 * @mixin GroupMember
 */
final class GroupMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'status' => strtoupper($this->status->value),
            // The days this person committed to, as the same bitmask the rest of
            // the API uses: Saturday 1 … Friday 64.
            'committedDaysMask' => $this->committed_days_mask,
            'joinedAt' => $this->joined_at?->toIso8601String(),
            'leftAt' => $this->left_at?->toIso8601String(),

            /*
             * Set only while a notice period is running, and computed from the
             * group's own period rather than a fixed number. Everybody in the group
             * can see it: a seat that is about to free up is the group's business.
             */
            'leavesOn' => $this->status === GroupMemberStatus::NoticeGiven
                ? LeaveGroupAction::leavesOn($this->resource)->toDateString()
                : null,

            // 🔒 A public first name and what has been verified. Nothing else.
            'person' => $this->when(
                $this->resource->relationLoaded('user'),
                fn () => [
                    'publicFirstName' => $this->user->public_first_name,
                    'trustLevel' => $this->user->trust_level,
                ],
            ),
        ];
    }
}
