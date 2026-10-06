<?php

namespace App\Domains\Payment\Actions;

use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Models\Booking;
use App\Domains\Payment\Enums\DriverFeeLedgerType;
use App\Domains\Payment\Enums\PaymentTransactionStatus;
use App\Domains\Payment\Enums\PaymentTransactionType;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Support\DriverFeeLedgerWriter;
use App\Domains\Payment\Support\PaymentSettings;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Models\Attendance;
use Illuminate\Support\Facades\DB;

/**
 * Records the cash collected on the day (Bible §8.1, the cash path; decision D18).
 *
 * The passenger paid the driver in the car. What the platform records, once the driver has said
 * they travelled and the safeguards have had their time:
 *
 * 1. a `payments` row — cash, `settled_offline`, the frozen split from the booking;
 * 2. the platform's fee as a DEBT the driver owes (`driver_fee_ledger`, `fee_due`) — the driver
 *    already holds the whole fare, so the fee is owed back (decided 2026-10-06: deducted from the
 *    driver, never added to the passenger);
 * 3. the booking as `paid`.
 *
 * 🔴 The gates, each of which is a promise made somewhere else:
 *
 * - **Two hours after the driver confirmed** (Master Plan §15.6) — a driver who tapped the wrong
 *   name has time to notice, and a passenger time to object.
 * - **Not disputed.** A passenger who says "I was not in that car" stops the collection until staff
 *   decide.
 * - **Travelled only** — present or late. A no-show is never collected on: the cancellation and
 *   no-show policy is still undecided (MASTER_PLAN §19 #7), and a guessed charge would be money
 *   taken from a real person on an assumption.
 *
 * A sweep rather than a delayed job per booking, like the rest of the platform's timed work: it is
 * idempotent (the payment's idempotency key is unique per booking, and only `not_due` bookings are
 * read), so a missed run is caught up by the next one and a double run settles nothing twice.
 *
 * Cash only. Online bookings wait for a payment provider.
 */
final readonly class SettleCashBookingsAction
{
    /**
     * @return int how many bookings were settled
     */
    public function execute(): int
    {
        $settled = 0;

        Attendance::query()
            ->whereIn('status', array_map(fn (AttendanceStatus $s) => $s->value, array_filter(
                AttendanceStatus::cases(),
                fn (AttendanceStatus $s) => $s->travelled(),
            )))
            ->whereNull('disputed_at')
            ->where('confirmed_at', '<=', now()->subMinutes(PaymentSettings::collectionDelayMinutes()))
            ->whereHas('booking', fn ($booking) => $booking
                ->where('payment_type', PaymentType::Cash->value)
                ->where('payment_status', PaymentStatus::NotDue->value))
            ->chunkById(200, function ($rows) use (&$settled): void {
                foreach ($rows as $attendance) {
                    $settled += $this->settle($attendance) ? 1 : 0;
                }
            }, 'booking_id');

        return $settled;
    }

    private function settle(Attendance $attendance): bool
    {
        return DB::transaction(function () use ($attendance): bool {
            $booking = Booking::query()->whereKey($attendance->booking_id)->lockForUpdate()->firstOrFail();

            // Re-read under the lock: a dispute or another run may have got here first.
            $fresh = Attendance::query()->whereKey($attendance->booking_id)->firstOrFail();

            if ($booking->payment_status !== PaymentStatus::NotDue || $fresh->disputed_at !== null) {
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
            $payment->confirmed_by_driver_at = $fresh->confirmed_at;
            $payment->save();

            if ($booking->platform_fee_snapshot_piastres > 0) {
                DriverFeeLedgerWriter::record(
                    $booking->driver_profile_id,
                    DriverFeeLedgerType::FeeDue,
                    $booking->platform_fee_snapshot_piastres,
                    bookingId: $booking->id,
                    paymentId: $payment->id,
                );
            }

            DriverFeeLedgerWriter::lockedBalance($booking->driver_profile_id)
                ->increment('lifetime_earnings_piastres', $booking->driver_amount_snapshot_piastres);

            $booking->forceFill(['payment_status' => PaymentStatus::Paid->value])->save();

            return true;
        });
    }
}
