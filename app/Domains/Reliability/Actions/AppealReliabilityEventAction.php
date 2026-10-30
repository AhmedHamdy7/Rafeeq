<?php

namespace App\Domains\Reliability\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Filing an appeal against a reliability event, and answering one (Feature 03 v5 §115).
 *
 * > *"...permanent person/account/phone ban **with appeal**."*
 *
 * 🔴 **The outcome has been storable since the ledger was built and nothing could ever write it**,
 * because there was no way to file. An appeal that cannot be filed is not an appeal, and a number
 * that can end somebody's account with no way to answer it is exactly what v5 attaches the word to.
 *
 * 🔴 **Filing changes nothing by itself.** The event keeps counting until a reviewer reverses it —
 * the same rule a disputed warning follows, and for the same reason: if asking were enough to
 * suspend the consequence, everything would be appealed as a matter of course and the score would
 * stop meaning anything. What filing buys her is that a person has to look, and that she is told
 * what they decided.
 *
 * 🔒 **An approved appeal reverses rather than deletes.** The row stays readable with its weight and
 * its reason intact — which is what lets her show she was cleared, and what lets a later reviewer
 * see that the first one was wrong. Deleting it would make both impossible and would quietly alter
 * a history somebody may be judged on.
 */
final readonly class AppealReliabilityEventAction
{
    /**
     * Her side: she asks for it to be looked at.
     */
    public function file(User $member, ReliabilityEvent $event, string $reason): ReliabilityEvent
    {
        if ($event->user_id !== $member->id) {
            // 404-shaped: a 403 would confirm the id names a real judgement about a real person.
            throw DomainException::of(ErrorCode::NotFound);
        }

        if (! $event->isAppealable()) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'appeal' => [$event->reversed_at !== null ? 'ALREADY_REVERSED' : 'ALREADY_APPEALED'],
            ]);
        }

        $event->forceFill([
            'appealed_at' => now(),
            'appeal_reason' => mb_substr($reason, 0, 255),
        ])->save();

        return $event->refresh();
    }

    /**
     * A reviewer approves it: the event stops counting and stays readable.
     *
     * @param  string  $reason  the reviewer's note, which the member is NOT shown — see below
     */
    public function approve(AdminUser $reviewer, ReliabilityEvent $event, string $reason): ReliabilityEvent
    {
        return DB::transaction(function () use ($reviewer, $event, $reason): ReliabilityEvent {
            $event->refresh();

            if ($event->reversed_at !== null) {
                throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                    'appeal' => ['ALREADY_REVERSED'],
                ]);
            }

            $event->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $reviewer->getKey(),
                'reversed_reason' => mb_substr($reason, 0, 255),
                // Cleared, in case it had been declined and is being reconsidered.
                'appeal_declined_at' => null,
            ])->save();

            $this->tell($event, approved: true);

            return $event->refresh();
        });
    }

    /**
     * A reviewer declines it: the event keeps counting, and she is told.
     */
    public function decline(AdminUser $reviewer, ReliabilityEvent $event, string $reason): ReliabilityEvent
    {
        return DB::transaction(function () use ($reviewer, $event, $reason): ReliabilityEvent {
            $event->refresh();

            if ($event->reversed_at !== null) {
                throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                    'appeal' => ['ALREADY_REVERSED'],
                ]);
            }

            $event->forceFill([
                'appeal_declined_at' => now(),
                /*
                 * Recorded on the same column the reversal uses, because it is the same thing from
                 * the desk's point of view: the reviewer's note about this decision. Which decision
                 * it was is told by which timestamp is set.
                 */
                'reversed_reason' => mb_substr($reason, 0, 255),
                'reversed_by' => $reviewer->getKey(),
            ])->save();

            $this->tell($event, approved: false);

            return $event->refresh();
        });
    }

    /**
     * She learns what was decided.
     *
     * 🔒 The reviewer's note is NOT sent. It is written for the desk — it may name another member,
     * quote a driver, or record something about an investigation — and v5's own privacy rule is to
     * avoid leaking one member's account of a morning to another. She is told the outcome, which is
     * the part that affects her, and support can explain the rest.
     */
    private function tell(ReliabilityEvent $event, bool $approved): void
    {
        $event->loadMissing('user');

        if ($event->user === null) {
            return;
        }

        Notifier::send($event->user, $approved
            ? NotificationType::AppealApproved
            : NotificationType::AppealDeclined, params: [
                'date' => $event->occurred_at->toFormattedDateString(),
            ], data: [
                'reliabilityEventId' => (string) $event->getKey(),
            ]);
    }
}
