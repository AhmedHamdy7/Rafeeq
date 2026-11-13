<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Identity\Models\User;
use App\Domains\Rating\Actions\RecomputeRatingStatsAction;
use App\Domains\Rating\Enums\ModerationStatus;
use App\Domains\Rating\Enums\ReviewReportStatus;
use App\Domains\Rating\Models\Rating;
use App\Domains\Reliability\Actions\AppealReliabilityEventAction;
use App\Domains\Reliability\Models\ReliabilityEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What staff may do to a member's record from the Reliability & ratings tab.
 *
 * - reverseEvent()   the event stops counting and stays readable. With an open appeal this IS
 *                    approving it (she is told); without one it is a correction staff found.
 * - declineAppeal()  her appeal is answered no: the event keeps counting, and she is told.
 * - hideRating()     a moderator takes a review down: it stops counting in her average, and the
 *                    complaints about it are closed as upheld.
 * - restoreRating()  puts it back, closing the complaints as dismissed.
 *
 * 🔒 Every step needs a reason and writes one audit row against the MEMBER (so it shows on her
 * activity), naming the event or rating it touched. Nothing is deleted.
 */
final readonly class ModerateMemberRecordAction
{
    public function __construct(
        private AppealReliabilityEventAction $appeals,
        private RecomputeRatingStatsAction $ratingStats,
    ) {}

    public function reverseEvent(AdminUser $by, User $member, ReliabilityEvent $event, string $reason): ReliabilityEvent
    {
        $this->authorize($by);
        $this->belongs($event->user_id === $member->id);

        if ($event->reversed_at !== null) {
            throw ValidationException::withMessages(['record' => __('admin/actions.already_reversed')]);
        }

        return DB::transaction(function () use ($by, $member, $event, $reason): ReliabilityEvent {
            $hadAppeal = $event->appealed_at !== null && $event->appeal_declined_at === null;

            if ($hadAppeal) {
                $event = $this->appeals->approve($by, $event, $reason);
            } else {
                // A correction nobody asked for: no "your appeal was approved" message about an appeal she never filed.
                $event->forceFill([
                    'reversed_at' => now(),
                    'reversed_by' => $by->getKey(),
                    'reversed_reason' => mb_substr($reason, 0, 255),
                ])->save();
            }

            AdminActionLog::record($by, 'reliability.reverse', $member, ['event' => $event->id, 'kind' => $event->kind->value, 'weight' => $event->weight], ['reversed' => true, 'appeal' => $hadAppeal], $reason);

            return $event->refresh();
        });
    }

    public function declineAppeal(AdminUser $by, User $member, ReliabilityEvent $event, string $reason): ReliabilityEvent
    {
        $this->authorize($by);
        $this->belongs($event->user_id === $member->id);

        if (! ReliabilityEvent::query()->whereKey($event->id)->awaitingAppealReview()->exists()) {
            throw ValidationException::withMessages(['record' => __('admin/actions.no_open_appeal')]);
        }

        return DB::transaction(function () use ($by, $member, $event, $reason): ReliabilityEvent {
            $event = $this->appeals->decline($by, $event, $reason);
            AdminActionLog::record($by, 'reliability.decline_appeal', $member, ['event' => $event->id], ['declined' => true], $reason);

            return $event;
        });
    }

    public function hideRating(AdminUser $by, User $member, Rating $rating, string $reason): Rating
    {
        return $this->moderate($by, $member, $rating, ModerationStatus::Hidden, ReviewReportStatus::Upheld, 'rating.hide', $reason);
    }

    public function restoreRating(AdminUser $by, User $member, Rating $rating, string $reason): Rating
    {
        return $this->moderate($by, $member, $rating, ModerationStatus::Clean, ReviewReportStatus::Dismissed, 'rating.restore', $reason);
    }

    private function moderate(AdminUser $by, User $member, Rating $rating, ModerationStatus $to, ReviewReportStatus $reports, string $audit, string $reason): Rating
    {
        $this->authorize($by);
        $this->belongs($rating->reviewed_user_id === $member->id);

        $from = $rating->moderation_status;

        if ($from === $to || ($to === ModerationStatus::Clean && $from !== ModerationStatus::Hidden && $rating->reports()->whereIn('status', [ReviewReportStatus::Pending->value, ReviewReportStatus::UnderReview->value])->doesntExist())) {
            throw ValidationException::withMessages(['record' => __('admin/actions.nothing_changed')]);
        }

        return DB::transaction(function () use ($by, $member, $rating, $from, $to, $reports, $audit, $reason): Rating {
            $rating->forceFill(['moderation_status' => $to->value])->save();

            $rating->reports()
                ->whereIn('status', [ReviewReportStatus::Pending->value, ReviewReportStatus::UnderReview->value])
                ->update(['status' => $reports->value, 'resolved_by' => $by->getKey(), 'updated_at' => now()]);

            // The average stops (or starts again) counting it at once.
            $this->ratingStats->execute($member->id);

            AdminActionLog::record($by, $audit, $member, ['rating' => $rating->id, 'moderation' => $from?->value], ['moderation' => $to->value], $reason);

            return $rating->refresh();
        });
    }

    private function authorize(AdminUser $by): void
    {
        if (! $by->can(AdminPermission::MemberSuspend->value)) {
            throw new AuthorizationException;
        }
    }

    private function belongs(bool $belongs): void
    {
        abort_unless($belongs, 404);
    }
}
