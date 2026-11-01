<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\Coupon;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\DebtNotices;
use App\Domains\Payment\Support\DebtSettings;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Payment\Support\SeatMoney;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * What staff do to a debt from the dashboard (ADM-03, ADM-04; PAY-09, PAY-10, PAY-34).
 *
 * | act              | ledger line              | rule   |
 * |------------------|--------------------------|--------|
 * | confirm payment  | `payment`, − the amount  | PAY-09 |
 * | refuse payment   | none                     | PAY-09 |
 * | coupon           | `coupon` for the harmed side, − value | PAY-34 |
 * | credit           | `credit`, − the amount   | PAY-10 |
 *
 * Every one is audited, and every one but confirming needs a written reason. Each line is keyed so
 * a double click writes it once. A line that takes money off a debt may lift a suspension for it —
 * the ledger does that on its own.
 */
final readonly class ManageDebtAction
{
    public function confirmPayment(DebtPayment $payment, AdminUser $admin): DebtPayment
    {
        $payment = DB::transaction(function () use ($payment, $admin): DebtPayment {
            $payment = $this->pending($payment);

            DebtLedger::record(
                $payment->user_id,
                $payment->side,
                DebtEntryType::Payment,
                -$payment->amount_piastres,
                'instapay_payment',
                debtPaymentId: $payment->id,
                adminId: $admin->id,
                idempotencyKey: "debt_payment:{$payment->id}",
            );

            $payment->forceFill([
                'status' => DebtPaymentStatus::Confirmed,
                'decided_by_admin_id' => $admin->id,
                'decided_at' => now(),
            ])->save();

            AdminActionLog::record($admin, 'debt.payment_confirm', $payment,
                before: ['status' => DebtPaymentStatus::PendingConfirmation->value],
                after: ['status' => DebtPaymentStatus::Confirmed->value, 'amount_piastres' => $payment->amount_piastres],
            );

            return $payment;
        });

        DebtNotices::send($payment->user_id, NotificationType::DebtPaymentConfirmed, ['amount' => $payment->amount_piastres]);

        return $payment;
    }

    public function rejectPayment(DebtPayment $payment, AdminUser $admin, string $note): DebtPayment
    {
        $payment = DB::transaction(function () use ($payment, $admin, $note): DebtPayment {
            $payment = $this->pending($payment);

            $payment->forceFill([
                'status' => DebtPaymentStatus::Rejected,
                'decided_by_admin_id' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            AdminActionLog::record($admin, 'debt.payment_reject', $payment,
                before: ['status' => DebtPaymentStatus::PendingConfirmation->value],
                after: ['status' => DebtPaymentStatus::Rejected->value],
                reason: $note,
            );

            return $payment;
        });

        DebtNotices::send($payment->user_id, NotificationType::DebtPaymentRejected);

        return $payment;
    }

    /**
     * PAY-34: the side that was harmed gets a coupon instead of the fee itself.
     *
     * 🔴 Only for a fee that was actually COLLECTED — RAFEEQ gives back part of money it has, never
     * money it is still owed. Its value is `debt.coupon_percent` of that fee, at most one seat.
     * The harmed side is the other person on the fee's booking. One coupon per fee.
     */
    public function issueCoupon(DebtEntry $fee, AdminUser $admin, string $note): Coupon
    {
        $coupon = DB::transaction(function () use ($fee, $admin, $note): Coupon {
            $fee = DebtEntry::query()->whereKey($fee->getKey())->lockForUpdate()->firstOrFail();
            $booking = $fee->booking_id === null ? null : Booking::query()->find($fee->booking_id);

            if ($fee->type !== DebtEntryType::Fee
                || $fee->amount_piastres <= 0
                || $booking === null
                || Coupon::query()->where('source_entry_id', $fee->id)->exists()
                || ! self::wasCollected($fee)) {
                throw DomainException::of(ErrorCode::CouponNotAllowed);
            }

            [$beneficiaryId, $side] = $fee->side === DebtSide::Passenger
                ? [$booking->driver_profile_id, DebtSide::Driver]
                : [$booking->passenger_user_id, DebtSide::Passenger];

            $value = min(
                intdiv($fee->amount_piastres * DebtSettings::couponPercent(), 100),
                SeatMoney::seatPricePiastres($booking),
            );

            if ($value <= 0) {
                throw DomainException::of(ErrorCode::CouponNotAllowed);
            }

            $line = DebtLedger::record(
                $beneficiaryId,
                $side,
                DebtEntryType::Coupon,
                -$value,
                'coupon',
                bookingId: $booking->id,
                adminId: $admin->id,
                meta: ['rule' => 'PAY-34', 'sourceEntryId' => $fee->id],
                idempotencyKey: "coupon:{$fee->id}",
            );

            $coupon = new Coupon;
            $coupon->user_id = $beneficiaryId;
            $coupon->side = $side;
            $coupon->value_piastres = $value;
            $coupon->source_entry_id = $fee->id;
            $coupon->debt_entry_id = $line->id;
            $coupon->booking_id = $booking->id;
            $coupon->issued_by_admin_id = $admin->id;
            $coupon->note = $note;
            $coupon->save();

            AdminActionLog::record($admin, 'debt.coupon', $coupon,
                after: ['user_id' => $beneficiaryId, 'side' => $side->value, 'value_piastres' => $value],
                reason: $note,
            );

            return $coupon;
        });

        DebtNotices::send($coupon->user_id, NotificationType::CouponIssued, ['amount' => $coupon->value_piastres]);

        return $coupon;
    }

    /**
     * PAY-10: money off her debt, written by staff (an appeal upheld after she paid, a correction).
     * A credit beyond what she owes is kept for next time; it is never paid out (Q-006).
     */
    public function credit(User $user, DebtSide $side, int $amountPiastres, AdminUser $admin, string $note): DebtEntry
    {
        return DB::transaction(function () use ($user, $side, $amountPiastres, $admin, $note): DebtEntry {
            $line = DebtLedger::record(
                $user->getKey(),
                $side,
                DebtEntryType::Credit,
                -abs($amountPiastres),
                'staff_credit',
                adminId: $admin->id,
                meta: ['note' => $note],
            );

            AdminActionLog::record($admin, 'debt.credit', $user,
                after: ['side' => $side->value, 'amount_piastres' => abs($amountPiastres)],
                reason: $note,
            );

            return $line;
        });
    }

    /**
     * Oldest-first payment: the fee is collected once the money still unpaid starts AFTER it.
     */
    public static function wasCollected(DebtEntry $fee): bool
    {
        $standing = DebtStanding::of($fee->user_id, $fee->side);

        return $standing->owedPiastres() === 0
            || ($standing->oldestUnpaidAt !== null && $standing->oldestUnpaidAt->gt($fee->created_at));
    }

    private function pending(DebtPayment $payment): DebtPayment
    {
        $payment = DebtPayment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

        if ($payment->status !== DebtPaymentStatus::PendingConfirmation) {
            throw DomainException::of(ErrorCode::DebtPaymentNotPending);
        }

        return $payment;
    }
}
