<?php

namespace App\Domains\Payment\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\MemberWarning;
use Carbon\CarbonImmutable;

/**
 * The passenger's three ladders (PAY-20 → PAY-22; Feature 03 backend file F-1 → F-3).
 *
 * | rung | no-show | late cancel (¼ of no-show) | lateness |
 * |------|---------|----------------------------|----------|
 * | 1    | warning | warning                    | warning  |
 * | 2    | 30 %    | 7.5 %                      | warning  |
 * | 3    | 50 %    | 12.5 %                     | 10 %     |
 * | 4    | 75 %    | 18.75 %                    | 20 %     |
 * | 5+   | 100 %   | 25 %                       | 25 %     |
 *
 * Of the seat price the booking froze (every seat on it). Each number is a setting.
 *
 * 🔴 The rung is counted on its OWN counter, from the warnings that still stand (an overturned one
 * does not count), going back only while they keep coming less than `fees.counter_reset_days`
 * apart. Ninety days with nothing starts her again at the first rung.
 */
final class PassengerFees
{
    /**
     * The fee at this rung, in basis points of the seat price (7.5 % = 750).
     */
    public static function basisPoints(WarningKind $kind, int $rung): int
    {
        return match ($kind) {
            WarningKind::NoShow => FeeSettings::noShowPercent($rung) * 100,
            WarningKind::LateCancel => intdiv(FeeSettings::noShowPercent($rung) * 100 * FeeSettings::lateCancelSharePercent(), 100),
            WarningKind::LateBoarding => FeeSettings::latenessPercent($rung) * 100,
            WarningKind::MissedCommitment => 0,
        };
    }

    public static function amountPiastres(Booking $booking, int $basisPoints): int
    {
        return intdiv(max(0, (int) $booking->price_snapshot_piastres) * $basisPoints, 10_000);
    }

    /**
     * The rung the NEXT warning of this kind lands on: one more than the standing warnings in the
     * current run, a run being warnings less than the reset gap apart, the newest less than the gap
     * before `$on`.
     */
    public static function nextRung(string $userId, WarningKind $kind, CarbonImmutable $on): int
    {
        $gap = FeeSettings::counterResetDays();

        $dates = MemberWarning::query()
            ->standing($userId, $kind)
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->pluck('occurred_on');

        $run = 0;
        $reference = $on->startOfDay();

        foreach ($dates as $date) {
            $date = CarbonImmutable::parse($date)->startOfDay();

            if ($date->diffInDays($reference, absolute: true) >= $gap) {
                break;
            }

            $run++;
            $reference = $date;
        }

        return $run + 1;
    }
}
