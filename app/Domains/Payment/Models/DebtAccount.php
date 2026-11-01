<?php

namespace App\Domains\Payment\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtSide;
use Database\Factories\DebtAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's balance on one side (driver or passenger) — a PROJECTION of {@see DebtEntry}.
 *
 * Positive: she owes RAFEEQ. Negative: a credit that discounts what she owes next (PAY-10). It is
 * never money held for her (Q-006): a negative balance cannot be withdrawn, only used up.
 *
 * Written only by the ledger under a row lock, and compared to the sum of the ledger every night
 * (`payments:reconcile-balances`). No `$fillable`.
 */
class DebtAccount extends Model
{
    /** @use HasFactory<DebtAccountFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'side' => DebtSide::class,
            'balance_piastres' => 'integer',
            'suspended_at' => 'datetime',
            'last_reminded_on' => 'date',
            'reconciled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
