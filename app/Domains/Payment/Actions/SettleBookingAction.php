<?php

namespace App\Domains\Payment\Actions;

use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Models\Booking;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Enums\PaymentTransactionStatus;
use App\Domains\Payment\Enums\PaymentTransactionType;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\FeeLines;
use App\Domains\Payment\Support\SeatMoney;
use Illuminate\Support\Facades\DB;

/**
 * The seat is PAID the moment the passenger is set down (PAY-07, decision D-0004; Feature 03
 * backend file L-1).
 *
 * The passenger paid the driver in the car. What is recorded, at once and in one transaction:
 *
 * 1. a `payments` row — cash, `settled_offline`, the frozen split from the booking;
 * 2. RAFEEQ's commission as the DRIVER's debt (`commission`, one per booking) — he already holds
 *    the whole fare, so the commission is owed back and settled every Friday (PAY-11);
 * 3. her fee line, if the ride carried one (PAY-16): a `fee_line` pair that moves the money off her
 *    passenger debt and onto the driver's, because he collected it in cash. A credit line (negative)
 *    moves the other way: her credit is used up, and the discount comes off his debt;
 * 4. the booking as `paid`.
 *
 * 🔴 This replaces "PAID two hours after attendance". The two-hour wait was a safeguard against a
 * driver tapping the wrong name; the partners chose the simpler rule (D-0004), and a mistake is now
 * corrected through a dispute (PAY-30 → PAY-32), which never un-pays the booking.
 *
 * Called from every way a journey ends with her in the car: Dropped off, Complete Trip (for those
 * still aboard), and Drop me here (`end-early` — a full fare, PAY-27). Idempotent: a booking already
 * paid is left alone, the payment key and the commission key are unique per booking.
 *
 * Cash only. An online booking waits for a payment provider (PAY-01: none in the beta).
 */
final readonly class SettleBookingAction
{
    /**
     * @return bool whether anything was settled
     */
    public function execute(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking): bool {
            $booking = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($booking->payment_status !== PaymentStatus::NotDue || $booking->payment_type !== PaymentType::Cash) {
                return false;
            }

            $payment = new Payment;
            $payment->fill([
                'booking_id' => $booking->id,
                'user_id' => $booking->passenger_user_id,
                'payment_type' => PaymentType::Cash->value,
                'amount_piastres' => $booking->price_snapshot_piastres,
                'platform_fee_piastres' => $booking->platform_fee_snapshot_piastres,
                'driver_amount_piastres' => $booking->driver_amount_snapshot_piastres,
                'type' => PaymentTransactionType::Charge->value,
                // 🔴 One charge per booking, whatever retries or overlapping runs happen.
                'idempotency_key' => "bk_{$booking->id}_charge",
            ]);
            // Not fillable: what the money did is decided here, not by whoever builds the row.
            $payment->status = PaymentTransactionStatus::SettledOffline->value;
            $payment->confirmed_by_driver_at = now();
            $payment->save();

            /*
             * Paid BEFORE the ledger lines, so the fee-line refresh the passenger's lines trigger
             * no longer sees this booking as her "next ride" — the line it carried stays on it as
             * the record of what was collected.
             */
            $booking->forceFill(['payment_status' => PaymentStatus::Paid->value])->save();

            $commission = SeatMoney::commissionPiastres($booking);

            if ($commission > 0) {
                DebtLedger::record(
                    $booking->driver_profile_id,
                    DebtSide::Driver,
                    DebtEntryType::Commission,
                    $commission,
                    'seat_commission',
                    bookingId: $booking->id,
                    idempotencyKey: "commission:{$booking->id}",
                );
            }

            $line = (int) $booking->fee_line_piastres;

            if ($line !== 0) {
                $reason = $booking->fee_line_reason ?? 'fee';

                DebtLedger::record(
                    $booking->passenger_user_id,
                    DebtSide::Passenger,
                    DebtEntryType::FeeLine,
                    -$line,
                    $reason,
                    bookingId: $booking->id,
                    idempotencyKey: "fee_line:{$booking->id}:passenger",
                );

                DebtLedger::record(
                    $booking->driver_profile_id,
                    DebtSide::Driver,
                    DebtEntryType::FeeLine,
                    $line,
                    $reason,
                    bookingId: $booking->id,
                    idempotencyKey: "fee_line:{$booking->id}:driver",
                );
            } else {
                // Nothing rode on this one, but it was her "next ride": the line, if she has a
                // debt, moves on to the ride after it.
                FeeLines::refresh($booking->passenger_user_id);
            }

            return true;
        });
    }
}
