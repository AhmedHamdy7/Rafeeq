<?php

namespace App\Http\Resources;

use App\Domains\Payment\Actions\SuspendForDebtAction;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Payment\Support\OpenDisputes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where she stands on one of her two debts (PAY-35, PAY-36). Her own, always.
 *
 * Amounts are piastres. A negative `balancePiastres` is a credit she will be discounted next time
 * (PAY-10); it is never paid out.
 *
 * @mixin DebtStanding
 */
final class DebtSideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $driver = $this->side === DebtSide::Driver;
        $disputes = OpenDisputes::listFor($this->userId, $this->side);
        $due = $driver ? $this->severePiastres > 0 : SuspendForDebtAction::passengerIsDue($this->resource);

        return [
            // What she owes now, never below zero.
            'owedPiastres' => $this->owedPiastres(),
            // Signed: positive owed, negative a credit.
            'balancePiastres' => $this->balancePiastres,
            // Driver side: owed from weeks whose Friday has passed (PAY-12). 0 on the passenger side.
            'overduePiastres' => $this->overduePiastres,
            // Late fees inside what she owes now (PAY-13).
            'lateFeePiastres' => $this->lateFeePiastres,
            /*
             * Driver side: the Friday 23:59 Cairo that matters now — the one she missed, or this
             * week's. Null on the passenger side, whose fee rides on her next trip (PAY-16).
             */
            'dueAt' => $driver ? $this->dueAt()->utc()->toIso8601String() : null,
            // Driver side: unpaid past its Friday — no publishing, no new riders (PAY-12).
            'blocked' => $driver && $this->isBlocked(),
            // Suspended for this debt: no driving (PAY-45), or no booking (PAY-46). Paying lifts it.
            'suspended' => $this->suspendedAt !== null,
            'suspendedAt' => $this->suspendedAt?->utc()->toIso8601String(),
            // When the oldest money still unpaid was added — the passenger's 14 days run from here (PAY-17).
            'oldestUnpaidAt' => $this->oldestUnpaidAt?->utc()->toIso8601String(),
            'openDisputes' => $disputes,
            'suspensionOnHold' => $this->suspendedAt === null && $disputes !== [] && $due,
        ];
    }
}
