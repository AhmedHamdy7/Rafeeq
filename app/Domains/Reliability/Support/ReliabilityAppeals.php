<?php

namespace App\Domains\Reliability\Support;

use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Reliability\Models\ReliabilityEventAppeal;
use Carbon\CarbonInterface;

final class ReliabilityAppeals
{
    public const int MAX = 2;

    public static function refusal(ReliabilityEvent $event): ?string
    {
        if ($event->reversed_at !== null) {
            return 'ALREADY_REVERSED';
        }

        $last = self::last($event);

        if ($last?->isPending() || ($last === null && $event->appealed_at !== null)) {
            return 'ALREADY_APPEALED';
        }

        if (self::made($event) >= self::MAX) {
            return 'NO_APPEALS_LEFT';
        }

        $closesAt = self::closesAt($event);

        if ($closesAt === null || $closesAt->isPast()) {
            return 'APPEAL_WINDOW_CLOSED';
        }

        if ($last !== null && self::evidenceCount($event) <= $last->evidence_count) {
            return 'NEW_EVIDENCE_REQUIRED';
        }

        return null;
    }

    public static function appealableUntil(ReliabilityEvent $event): ?CarbonInterface
    {
        $refusal = self::refusal($event);

        return $refusal === null || $refusal === 'NEW_EVIDENCE_REQUIRED'
            ? self::closesAt($event)
            : null;
    }

    public static function acceptsEvidence(ReliabilityEvent $event): bool
    {
        return self::appealableUntil($event) !== null;
    }

    public static function left(ReliabilityEvent $event): int
    {
        if ($event->reversed_at !== null) {
            return 0;
        }

        return max(0, self::MAX - self::made($event));
    }

    public static function made(ReliabilityEvent $event): int
    {
        $event->loadMissing('appeals');

        return $event->appeals->count();
    }

    public static function last(ReliabilityEvent $event): ?ReliabilityEventAppeal
    {
        $event->loadMissing('appeals');

        return $event->appeals->sortBy('round')->last();
    }

    public static function evidenceCount(ReliabilityEvent $event): int
    {
        $event->loadMissing('evidence');

        return $event->evidence->count();
    }

    private static function closesAt(ReliabilityEvent $event): ?CarbonInterface
    {
        $from = self::last($event)?->resolved_at ?? $event->created_at ?? $event->occurred_at;

        return $from?->copy()->addHours(FeeSettings::appealHours());
    }
}
