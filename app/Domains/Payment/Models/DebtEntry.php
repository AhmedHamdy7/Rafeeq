<?php

namespace App\Domains\Payment\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Shared\Concerns\IsAppendOnly;
use Database\Factories\DebtEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔒 INSERT-only — one line of somebody's debt (PAY-08, decision D-0005).
 *
 * Written only by {@see DebtLedger}, which keeps
 * {@see DebtAccount} (the projection) in the same transaction. A line is never edited: a fee
 * overturned on appeal is answered by a `credit` that names it in `reverses_entry_id`.
 *
 * No `$fillable`: nothing about a ledger line is ever submitted.
 */
class DebtEntry extends Model
{
    /** @use HasFactory<DebtEntryFactory> */
    use HasFactory, HasUlids, IsAppendOnly;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'side' => DebtSide::class,
            'type' => DebtEntryType::class,
            'amount_piastres' => 'integer',
            'balance_after_piastres' => 'integer',
            'week_of' => 'date',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function debtPayment(): BelongsTo
    {
        return $this->belongsTo(DebtPayment::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }
}
