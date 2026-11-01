<?php

namespace App\Http\Resources;

use App\Domains\Payment\Models\DebtEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of her statement (PAY-35): something added to her debt, or taken off it.
 *
 * @mixin DebtEntry
 */
final class DebtEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /*
             * `commission`, `fee`, `late_fee`, `fee_line` add to it; `payment`, `credit`, `coupon`,
             * `write_off` take it down; `adjustment` either way.
             */
            'type' => $this->type->value,
            // Signed: positive adds to what she owes, negative takes it down.
            'amountPiastres' => $this->amount_piastres,
            // What she owed on this side right after this line.
            'balanceAfterPiastres' => $this->balance_after_piastres,
            // Why, as a stable code the app translates (`passenger_no_show`, `paid_after_friday`…).
            'reasonCode' => $this->reason_code,
            'bookingId' => $this->booking_id,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
