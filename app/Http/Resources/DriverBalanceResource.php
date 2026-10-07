<?php

namespace App\Http\Resources;

use App\Domains\Payment\Models\DriverBalance;
use App\Domains\Payment\Support\PaymentSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a driver owes and what she has earned (Bible §8, the driver's side of the money).
 *
 * 🔴 The shape is built around one fact that the raw columns do not convey: **a cash trip leaves
 * the driver owing us the platform's fee.** She collected the whole fare at the roadside, so the 3%
 * is a debt rather than a deduction, and it accrues quietly until it stops her publishing. Somebody
 * who only ever sees "earnings" and then cannot publish has been told nothing.
 *
 * So this answers, in order: what do I owe · how close is that to the limit · what happens when I
 * reach it · what have I earned.
 *
 * 🔒 The debt cap is read live rather than taken from `is_blocked_from_publishing`, because that
 * column is a projection a nightly job maintains and the cap is a setting staff can change at any
 * moment. A driver told she may publish when the current cap says otherwise would hit a refusal she
 * had just been promised would not come.
 *
 * @mixin DriverBalance
 */
final class DriverBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $outstanding = (int) $this->outstanding_fee_piastres;
        $cap = PaymentSettings::maxDriverDebtPiastres();

        return [
            /*
             * What she owes the platform, in piastres. Fees from cash trips she has already been
             * paid for in full.
             */
            'outstandingFeePiastres' => $outstanding,

            /*
             * The cap and the headroom, both stated. A client that showed only the debt would have
             * to hard-code the limit to draw a progress bar — and the limit is a runtime setting,
             * so the hard-coded one would be wrong the first time staff moved it.
             */
            'debtCapPiastres' => $cap,
            // Cast, because `max()` returns a union and the published contract would carry an
            // untyped hole where the mobile team needs an integer.
            'remainingBeforeBlockPiastres' => (int) max(0, $cap - $outstanding),

            /*
             * 🔴 Computed from the CURRENT cap, not read from the stored flag. The flag is a
             * projection maintained by the nightly reconciliation; the cap is a setting. Trusting
             * the flag would tell a driver she may publish minutes after staff lowered the limit
             * below her balance, and the refusal would arrive as a surprise.
             */
            'isBlockedFromPublishing' => $outstanding > $cap,

            /*
             * What being blocked actually means, said rather than implied: she cannot publish
             * anything NEW. Every run already booked goes ahead, because the passengers on it did
             * nothing wrong. A screen that said "account blocked" would be both frightening and
             * untrue.
             */
            'existingRunsContinue' => true,

            'blockReason' => $this->block_reason,

            'lifetimeEarningsPiastres' => (int) $this->lifetime_earnings_piastres,
            'lastSettledAt' => $this->last_settled_at?->toIso8601String(),
        ];
    }
}
