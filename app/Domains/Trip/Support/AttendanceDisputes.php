<?php

namespace App\Domains\Trip\Support;

use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Models\Attendance;
use Carbon\CarbonInterface;

final class AttendanceDisputes
{
    public static function closesAt(Attendance $attendance): CarbonInterface
    {
        $decidedAt = $attendance->confirmed_at ?? $attendance->updated_at;

        return $decidedAt->copy()->addHours(TripSettings::disputeWindowHours());
    }

    public static function disputableUntil(Attendance $attendance): ?CarbonInterface
    {
        if ($attendance->disputed_at !== null || ! $attendance->status->isTerminal()) {
            return null;
        }

        $closesAt = self::closesAt($attendance);

        return $closesAt->isFuture() ? $closesAt : null;
    }

    /**
     * @return array{confirmedAt: string|null, disputableUntil: string|null, dispute: array<string, string|null>|null}|null
     */
    public static function noShowFor(?Attendance $attendance): ?array
    {
        if ($attendance === null || $attendance->status !== AttendanceStatus::PassengerNoShow) {
            return null;
        }

        return [
            'confirmedAt' => $attendance->confirmed_at?->toIso8601String(),
            'disputableUntil' => self::disputableUntil($attendance)?->toIso8601String(),
            'dispute' => $attendance->disputed_at === null ? null : [
                'status' => $attendance->dispute_resolution === null ? 'OPEN' : 'DECIDED',
                'reason' => $attendance->dispute_reason,
                'disputedAt' => $attendance->disputed_at->toIso8601String(),
                'resolution' => $attendance->dispute_resolution?->value,
                'resolvedAt' => $attendance->dispute_resolved_at?->toIso8601String(),
                'note' => $attendance->dispute_note,
            ],
        ];
    }
}
