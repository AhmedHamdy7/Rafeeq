<?php

namespace App\Domains\Payment\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a driver pays when he lets passengers down (PAY-23 → PAY-29; Feature 03 backend file F-5 →
 * F-10). Always a share of each affected seat's frozen price, always on HIS side of the ledger,
 * always once per booking per kind (keyed).
 *
 * | what                                        | fee per affected seat          | rule   |
 * |---------------------------------------------|--------------------------------|--------|
 * | cancels before the trip (one passenger, the day, pause, delete, replace vehicle, block) | ≥ 16h: 0 · 10–16h: 10 % · < 10h: 25 % | PAY-23 |
 * | ends a run that set off (any reason), or drops her early without asking | 100 % | PAY-24, PAY-25, PAY-28 |
 * | never came (the run expired)                | 50 %                           | PAY-26 |
 * | late, and she left or switched              | 25 % (void if it became a no-show) | PAY-29 |
 *
 * Every one can be appealed within 48 hours (`POST /account/debt/entries/{entry}/appeal`); an upheld
 * appeal credits it back (PAY-10).
 */
final class DriverFees
{
    public const string CANCEL = 'driver_cancel';

    public const string ACTIVE_CANCEL = 'driver_ended_trip';

    public const string NO_SHOW = 'driver_no_show';

    public const string LATE = 'driver_late';

    /**
     * Fees written in this transaction, waiting to be told: one message per driver per kind, not
     * one per seat — a cancelled day with four riders is one decision.
     *
     * @var array<string, int>
     */
    private static array $untold = [];

    /**
     * PAY-23: the share of each seat a cancellation this far ahead of departure costs.
     */
    public static function cancelPercent(?CarbonInterface $departureAt, ?CarbonInterface $at = null): int
    {
        if ($departureAt === null) {
            return 0;
        }

        $hours = ($at ?? now())->diffInMinutes($departureAt, absolute: false) / 60;

        return match (true) {
            $hours >= FeeSettings::driverCancelFreeHours() => 0,
            $hours >= FeeSettings::driverCancelShortHours() => FeeSettings::driverCancelMidPercent(),
            default => FeeSettings::driverCancelShortPercent(),
        };
    }

    public static function amount(Booking $booking, int $percent): int
    {
        return intdiv(max(0, (int) $booking->price_snapshot_piastres) * $percent, 100);
    }

    /**
     * PAY-23 for one booking he is cancelling now. Nothing is written at 0 %.
     */
    public static function chargeCancellation(Booking $booking): ?DebtEntry
    {
        $booking->loadMissing('scheduledTrip');

        return self::charge($booking, self::CANCEL, self::cancelPercent($booking->scheduledTrip?->departure_at), 'PAY-23');
    }

    /**
     * Writes one fee on the driver's side, once per booking per kind.
     */
    public static function charge(Booking $booking, string $kind, int $percent, string $rule): ?DebtEntry
    {
        $amount = self::amount($booking, $percent);
        $driver = User::query()->find($booking->driver_profile_id);

        if ($amount <= 0 || $driver === null) {
            return null;
        }

        $key = "{$kind}:{$booking->getKey()}";

        if (DebtEntry::query()->where('idempotency_key', $key)->exists()) {
            return null;
        }

        $line = DebtLedger::charge(
            $driver,
            DebtEntryType::Fee,
            $amount,
            $kind,
            $booking,
            meta: ['rule' => $rule, 'percent' => $percent],
            side: DebtSide::Driver,
            idempotencyKey: $key,
        );

        self::tellLater((string) $driver->id, $kind, $amount);

        return $line;
    }

    /**
     * The amount, the reason and the 48 hours to appeal, once the money is safely written.
     */
    private static function tellLater(string $driverId, string $kind, int $amount): void
    {
        $slot = "{$driverId}|{$kind}";
        self::$untold[$slot] = (self::$untold[$slot] ?? 0) + $amount;

        // Registered every time; the first to run sends the total and the rest find nothing.
        DB::afterCommit(function () use ($slot, $driverId, $kind): void {
            $total = self::$untold[$slot] ?? 0;
            unset(self::$untold[$slot]);

            $driver = User::query()->find($driverId);

            if ($driver === null || $total <= 0) {
                return;
            }

            Notifier::send($driver, match ($kind) {
                self::ACTIVE_CANCEL => NotificationType::DriverEndedTripFee,
                self::NO_SHOW => NotificationType::DriverNoShowFee,
                self::LATE => NotificationType::DriverLateFee,
                default => NotificationType::DriverCancelFee,
            }, params: [
                'amount' => DebtNotices::egp($total),
                'hours' => (string) FeeSettings::appealHours(),
            ]);
        });
    }

    /**
     * Takes a fee off again (PAY-29: a lateness that became a no-show is charged as the no-show).
     */
    public static function void(Booking $booking, string $kind, string $reason): void
    {
        $line = DebtEntry::query()->where('idempotency_key', "{$kind}:{$booking->getKey()}")->first();
        $driver = User::query()->find($booking->driver_profile_id);

        if ($line === null || $driver === null) {
            return;
        }

        DebtLedger::credit(
            $driver,
            DebtEntryType::Credit,
            (int) $line->amount_piastres,
            $reason,
            $booking,
            meta: ['voids' => $line->id],
            side: DebtSide::Driver,
            idempotencyKey: "void:{$kind}:{$booking->getKey()}",
        );
    }

    /**
     * C-9: what cancelling these seats now would cost him, before he confirms.
     *
     * @param  Collection<int, Booking>  $bookings  confirmed, upcoming
     * @return array{affectedSeats: int, feePiastres: int, reason: string, perTrip: list<array{tripId: string, departureAt: string|null, seats: int, percent: int, feePiastres: int}>}
     */
    public static function preview(Collection $bookings, bool $charged = true): array
    {
        $perTrip = [];

        foreach ($bookings->groupBy('scheduled_trip_id') as $tripId => $onTrip) {
            $trip = $onTrip->first()->scheduledTrip;
            $percent = $charged ? self::cancelPercent($trip?->departure_at) : 0;

            $perTrip[] = [
                'tripId' => (string) $tripId,
                'departureAt' => $trip?->departure_at?->toIso8601String(),
                'seats' => (int) $onTrip->sum('seats_reserved'),
                'percent' => $percent,
                'feePiastres' => (int) $onTrip->sum(fn (Booking $b) => self::amount($b, $percent)),
            ];
        }

        $fee = array_sum(array_column($perTrip, 'feePiastres'));
        $percents = array_column($perTrip, 'percent');

        return [
            'affectedSeats' => (int) $bookings->sum('seats_reserved'),
            'feePiastres' => $fee,
            // Which rung, as a stable code: `none`, `mid_notice` (10–16h), `short_notice` (< 10h).
            'reason' => match (true) {
                $fee === 0 => 'none',
                in_array(FeeSettings::driverCancelShortPercent(), $percents, true) => 'short_notice',
                default => 'mid_notice',
            },
            'perTrip' => $perTrip,
        ];
    }
}
