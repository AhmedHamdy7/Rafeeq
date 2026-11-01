<?php

namespace App\Domains\Payment\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Verification\Support\DocumentIntake;
use Illuminate\Http\UploadedFile;

/**
 * "I paid": an InstaPay transfer she says she sent towards one of her two debts (PAY-09, ADM-03).
 *
 * 🔴 Nothing comes off the debt here. The row waits `pending_confirmation` until staff see the money
 * arrive ({@see DecideDebtPaymentAction}); only then does the ledger get its `payment` line. Paying
 * MORE than she owes is allowed: the rest becomes a credit on the same side (PAY-10).
 *
 * The receipt is optional and goes through the same intake as identity documents and report
 * evidence: scanned, re-encoded, stored privately, hashed. Its path is never returned.
 */
final readonly class SubmitDebtPaymentAction
{
    public function __construct(private DocumentIntake $intake) {}

    public function execute(
        User $user,
        DebtSide $side,
        int $amountPiastres,
        string $instapayReference,
        ?UploadedFile $receipt = null,
    ): DebtPayment {
        if (DebtLedger::owedBy($user, $side) <= 0) {
            throw DomainException::of(ErrorCode::DebtNothingOwed);
        }

        $stored = $receipt === null ? null : $this->intake->accept($user, $receipt, auditContext: [
            'purpose' => 'debt_payment_receipt',
        ]);

        $payment = new DebtPayment;
        $payment->user_id = $user->getKey();
        $payment->side = $side;
        $payment->amount_piastres = $amountPiastres;
        $payment->instapay_reference = $instapayReference;
        $payment->receipt_path = $stored['path'] ?? null;
        $payment->receipt_hash = $stored['hash'] ?? null;
        $payment->status = DebtPaymentStatus::PendingConfirmation;
        $payment->save();

        return $payment;
    }
}
