<?php

namespace App\Domains\Payment\Support;

use App\Domains\Payment\Enums\DriverFeeLedgerType;
use App\Domains\Payment\Models\DriverBalance;
use App\Domains\Payment\Models\DriverFeeLedger;
use Illuminate\Support\Facades\DB;

/**
 * The only way a driver's fee debt changes (Bible §8.1, pitfall #39).
 *
 * 🔴 The ledger is the truth and `driver_balances` is a projection of it. Both are written here and
 * nowhere else, in one transaction, under a lock on the driver's balance row — so `balance_after`
 * on each ledger row is the running total at that moment and two settlements finishing in the same
 * second cannot both read the old figure. The nightly reconciliation compares the two and shouts if
 * they ever disagree.
 *
 * Amounts are signed: positive adds to what the driver owes (`fee_due`), negative pays it down
 * (`fee_settled`, `write_off`), and `adjustment` may be either.
 */
final class DriverFeeLedgerWriter
{
    public static function record(
        string $driverId,
        DriverFeeLedgerType $type,
        int $amountPiastres,
        ?string $bookingId = null,
        ?string $paymentId = null,
        ?string $note = null,
    ): DriverFeeLedger {
        return DB::transaction(function () use ($driverId, $type, $amountPiastres, $bookingId, $paymentId, $note): DriverFeeLedger {
            $balance = self::lockedBalance($driverId);

            // Never below zero: paying off more than is owed is a credit the platform does not
            // hold, and an unsigned projection cannot represent it anyway.
            $after = max(0, $balance->outstanding_fee_piastres + $amountPiastres);

            $entry = DriverFeeLedger::create([
                'driver_profile_id' => $driverId,
                'booking_id' => $bookingId,
                'type' => $type->value,
                'amount_piastres' => $after - $balance->outstanding_fee_piastres,
                'balance_after_piastres' => $after,
                'settled_from_payment_id' => $paymentId,
                'note' => $note,
            ]);

            $overCap = $after > PaymentSettings::maxDriverDebtPiastres();

            $balance->forceFill([
                'outstanding_fee_piastres' => $after,
                'is_blocked_from_publishing' => $overCap,
                'block_reason' => $overCap ? 'debt_cap' : null,
                'last_settled_at' => $amountPiastres < 0 ? now() : $balance->last_settled_at,
            ])->save();

            return $entry;
        });
    }

    /**
     * The driver's balance row, created on first use and locked for the rest of the transaction.
     */
    public static function lockedBalance(string $driverId): DriverBalance
    {
        DriverBalance::query()->firstOrCreate(['driver_profile_id' => $driverId]);

        return DriverBalance::query()->whereKey($driverId)->lockForUpdate()->firstOrFail();
    }
}
