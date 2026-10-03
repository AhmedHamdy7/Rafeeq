<?php

namespace App\Domains\Rating\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Rating\Enums\ModerationStatus;
use App\Domains\Rating\Enums\ReviewReportStatus;
use App\Domains\Rating\Models\Rating;
use App\Domains\Rating\Models\ReviewReport;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Reporting a review as abusive (Chapter 9, "review reports + moderation").
 *
 * 🔴 **A report flags; it does not take down.** `moderation_status` moves to `flagged`, which is a
 * QUEUE and not a verdict, and a flagged review keeps counting towards the average and keeps
 * appearing on the profile until a human decides otherwise.
 *
 * That ordering is the whole design, and the alternative is worse than it looks. If a report hid the
 * review, or dropped it from the average, then "report every review under four stars" would be an
 * effective and entirely mechanical way to launder a record — and the people most motivated to do
 * it are exactly the ones a rating system exists to surface. Only `ModerationStatus::Hidden`, set by
 * a moderator, removes anything.
 *
 * 🔒 Only the SUBJECT may report a review. Not the reviewer (they wrote it), and not a passer-by:
 * a stranger reporting other people's reviews is a way to put a moderation queue to work against
 * somebody, and the queue is a scarce human resource.
 *
 * 🔒 And the report does not reveal who wrote the review. The subject reports "this review", by its
 * id, which they got from an anonymous listing — see `PublicReviewResource` on why a name and an
 * exact date are both withheld.
 */
final readonly class ReportReviewAction
{
    public function execute(User $reporter, Rating $rating, string $reason): ReviewReport
    {
        /*
         * 🔒 Only about yourself, and only once it is readable. A hidden rating is one nobody has
         * seen — including its subject — so a report on it would be a report on something the
         * reporter could only know about by having been told, which double-blind exists to
         * prevent. 404-shaped for both cases, so neither is distinguishable from a wrong id.
         */
        if ($rating->reviewed_user_id !== $reporter->id || ! $rating->isVisible()) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        $already = ReviewReport::query()
            ->where('rating_id', $rating->id)
            ->where('reporter_id', $reporter->id)
            ->exists();

        if ($already) {
            throw DomainException::of(ErrorCode::ReviewAlreadyReported);
        }

        return DB::transaction(function () use ($reporter, $rating, $reason): ReviewReport {
            $report = new ReviewReport;

            $report->fill([
                'rating_id' => $rating->id,
                'reporter_id' => $reporter->id,
                'reason' => $reason,
            ]);

            $report->status = ReviewReportStatus::Pending->value;

            $report->save();

            /*
             * Flagged, not hidden. See the class note: a complaint puts the review in front of a
             * human, and nothing else. `clean` is only overwritten when it is still `clean`, so a
             * second report cannot walk back a moderator's decision.
             */
            if ($rating->moderation_status === ModerationStatus::Clean) {
                $rating->forceFill(['moderation_status' => ModerationStatus::Flagged->value])->save();
            }

            return $report;
        });
    }
}
