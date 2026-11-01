<?php

namespace App\Http\Resources;

use App\Domains\Payment\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A coupon RAFEEQ gave her for a ride where the other side was at fault (PAY-34). Already taken off
 * her debt on `side`; shown so she knows why.
 *
 * @mixin Coupon
 */
final class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'side' => $this->side->value,
            'valuePiastres' => $this->value_piastres,
            'bookingId' => $this->booking_id,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
