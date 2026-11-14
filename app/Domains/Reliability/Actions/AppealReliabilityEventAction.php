<?php

namespace App\Domains\Reliability\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Reliability\Enums\AppealDeclineReason;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Reliability\Models\ReliabilityEventAppeal;
use App\Domains\Reliability\Support\EventFee;
use App\Domains\Reliability\Support\ReliabilityAppeals;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\DisputeResolution;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class AppealReliabilityEventAction
{
    public function file(User $member, ReliabilityEvent $event, string $reason): ReliabilityEvent
    {
        if ($event->user_id !== $member->id) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        return DB::transaction(function () use ($member, $event, $reason): ReliabilityEvent {
            $event = ReliabilityEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            $refusal = ReliabilityAppeals::refusal($event);

            if ($refusal !== null) {
                throw DomainException::of(ErrorCode::ValidationFailed, fields: ['appeal' => [$refusal]]);
            }

            $targets = self::siblings($event)
                ->filter(fn (ReliabilityEvent $sibling) => ReliabilityAppeals::refusal($sibling) === null)
                ->prepend($event);

            foreach ($targets as $target) {
                $this->open($member, $target, $reason);
            }

            return $event->refresh();
        });
    }

    /**
     * @return Collection<int, ReliabilityEvent>
     */
    public static function group(User $member, string $groupId): Collection
    {
        return ReliabilityEvent::query()
            ->where('user_id', $member->id)
            ->where('group_id', strtoupper($groupId))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, ReliabilityEvent>
     */
    public static function siblings(ReliabilityEvent $event, bool $lock = true): Collection
    {
        if ($event->group_id === null) {
            return new Collection;
        }

        return ReliabilityEvent::query()
            ->where('user_id', $event->user_id)
            ->where('group_id', $event->group_id)
            ->whereKeyNot($event->getKey())
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get();
    }

    private function open(User $member, ReliabilityEvent $event, string $reason): void
    {
        $appeal = new ReliabilityEventAppeal;

        $appeal->forceFill([
            'reliability_event_id' => $event->getKey(),
            'user_id' => $member->id,
            'round' => ReliabilityAppeals::made($event) + 1,
            'reason' => $reason,
            'evidence_count' => ReliabilityAppeals::evidenceCount($event),
        ])->save();

        $event->forceFill([
            'appealed_at' => now(),
            'appeal_reason' => mb_substr($reason, 0, 255),
            'appeal_declined_at' => null,
        ])->save();
    }

    public function approve(AdminUser $reviewer, ReliabilityEvent $event, string $reason, bool $reverseFee = true, bool $notify = true): ReliabilityEvent
    {
        return DB::transaction(function () use ($reviewer, $event, $reason, $reverseFee, $notify): ReliabilityEvent {
            $event->refresh();

            if ($event->reversed_at !== null) {
                throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                    'appeal' => ['ALREADY_REVERSED'],
                ]);
            }

            foreach ($this->decidedTogether($event) as $target) {
                $target->forceFill([
                    'reversed_at' => now(),
                    'reversed_by' => $reviewer->getKey(),
                    'reversed_reason' => mb_substr($reason, 0, 255),
                    'appeal_declined_at' => null,
                ])->save();

                $this->resolve($target, $reviewer, DisputeResolution::Overturned, $reason, null);

                if ($reverseFee || $target->isNot($event)) {
                    EventFee::reverse($target, $reviewer, $reason);
                }
            }

            if ($notify) {
                $this->tell($event, approved: true);
            }

            return $event->refresh();
        });
    }

    public function decline(
        AdminUser $reviewer,
        ReliabilityEvent $event,
        string $reason,
        AppealDeclineReason $code = AppealDeclineReason::NotEnoughEvidence,
    ): ReliabilityEvent {
        return DB::transaction(function () use ($reviewer, $event, $reason, $code): ReliabilityEvent {
            $event->refresh();

            if ($event->reversed_at !== null) {
                throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                    'appeal' => ['ALREADY_REVERSED'],
                ]);
            }

            foreach ($this->decidedTogether($event) as $target) {
                $target->forceFill([
                    'appeal_declined_at' => now(),
                    'reversed_reason' => mb_substr($reason, 0, 255),
                    'reversed_by' => $reviewer->getKey(),
                ])->save();

                $this->resolve($target, $reviewer, DisputeResolution::Upheld, $reason, $code);
            }

            $this->tell($event, approved: false);

            return $event->refresh();
        });
    }

    /**
     * @return Collection<int, ReliabilityEvent>
     */
    private function decidedTogether(ReliabilityEvent $event): Collection
    {
        if (! (ReliabilityAppeals::last($event)?->isPending() ?? false)) {
            return new Collection([$event]);
        }

        return self::siblings($event)
            ->filter(fn (ReliabilityEvent $sibling) => $sibling->reversed_at === null
                && (ReliabilityAppeals::last($sibling)?->isPending() ?? false))
            ->prepend($event);
    }

    private function resolve(
        ReliabilityEvent $event,
        AdminUser $reviewer,
        DisputeResolution $resolution,
        string $note,
        ?AppealDeclineReason $code,
    ): void {
        ReliabilityEventAppeal::query()
            ->where('reliability_event_id', $event->getKey())
            ->whereNull('resolution')
            ->get()
            ->each(fn (ReliabilityEventAppeal $appeal) => $appeal->forceFill([
                'resolution' => $resolution,
                'decline_reason_code' => $code,
                'resolved_by_admin_id' => $reviewer->getKey(),
                'resolved_at' => now(),
                'resolution_note' => mb_substr($note, 0, 255),
            ])->save());
    }

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
