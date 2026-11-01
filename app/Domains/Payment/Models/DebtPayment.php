<?php

namespace App\Domains\Payment\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Enums\DebtSide;
use Database\Factories\DebtPaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An InstaPay transfer a member says she made towards what she owes (PAY-09, ADM-03).
 *
 * Nothing comes off a debt while this is `pending_confirmation`. Staff confirm it in the dashboard
 * once they see the money, and only then does the ledger get its `payment` line.
 *
 * 🔒 `receipt_path` is never returned to anybody; staff open the receipt through the session.
 */
class DebtPayment extends Model
{
    /** @use HasFactory<DebtPaymentFactory> */
    use HasFactory, HasUlids;

    protected $hidden = ['receipt_path'];

    protected function casts(): array
    {
        return [
            'side' => DebtSide::class,
            'status' => DebtPaymentStatus::class,
            'amount_piastres' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by_admin_id');
    }
}
