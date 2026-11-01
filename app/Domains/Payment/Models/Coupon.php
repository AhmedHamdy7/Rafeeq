<?php

namespace App\Domains\Payment\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtSide;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A coupon an admin gave the side that was harmed (PAY-34, ADM-04, decision D-0009 / D-0017).
 *
 * Its value is already on the beneficiary's ledger as a `coupon` line (`debt_entry_id`), so it
 * discounts her next commission (driver) or her next fare line (passenger) by the ordinary
 * arithmetic of the balance. This row is the record of WHY: which collected fee justified it.
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory, HasUlids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'side' => DebtSide::class,
            'value_piastres' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(DebtEntry::class, 'source_entry_id');
    }

    public function debtEntry(): BelongsTo
    {
        return $this->belongsTo(DebtEntry::class, 'debt_entry_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'issued_by_admin_id');
    }
}
