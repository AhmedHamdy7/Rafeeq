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
    /**
     * TRIPS-18: what cancelling her own seat NOW would cost her, before she confirms — worked out
     * the way `CancelBookingAction` will work it out (PAY-21), so the sheet and the charge agree.
     *
     * Inside `fees.late_cancel_hours` the cancel goes on her late-cancel counter: the first rungs
     * may be a warning with no money (`feePiastres: 0`, `warning: true`), later ones a quarter of
     * the no-show fee. A fee is held until departure and dropped if a waiting passenger takes the
     * seat (R-8), which is why `waivedIfRefilled` is part of the answer.
     *
     * @return array{feePiastres: int, reason: string, warning: bool, rung: int|null, waivedIfRefilled: bool}
     */
    public static function cancelPreview(Booking $booking): array
    {
        $departure = $booking->scheduledTrip?->departure_at;
        $hoursAhead = $departure === null ? null : now()->diffInHours($departure, absolute: false);

        if ($hoursAhead === null || $hoursAhead >= FeeSettings::lateCancelHours()) {
            return ['feePiastres' => 0, 'reason' => 'none', 'warning' => false, 'rung' => null, 'waivedIfRefilled' => false];
        }

        $on = CarbonImmutable::parse($booking->scheduledTrip->trip_date ?? now());
        $rung = self::nextRung((string) $booking->passenger_user_id, WarningKind::LateCancel, $on);
        $fee = self::amountPiastres($booking, self::basisPoints(WarningKind::LateCancel, $rung));

        return [
            'feePiastres' => $fee,
            // `late_cancel_warning`: on the counter, no money this time. `late_cancel_fee`: money.
            'reason' => $fee > 0 ? 'late_cancel_fee' : 'late_cancel_warning',
            'warning' => true,
            'rung' => $rung,
            'waivedIfRefilled' => $fee > 0,
        ];
    }

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
