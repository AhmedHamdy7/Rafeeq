<?php

namespace App\Domains\Payment\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Payment\Enums\DriverFeeLedgerType;
use App\Domains\Shared\Concerns\IsAppendOnly;
use Database\Factories\DriverFeeLedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔒 INSERT-only — the source of truth for driver cash-fee debt. See
 * {@see DriverBalance}, its reconciled projection.
 */
#[Fillable(['driver_profile_id', 'booking_id', 'type', 'amount_piastres', 'balance_after_piastres', 'settled_from_payment_id', 'note'])]
class DriverFeeLedger extends Model
{
    /** @use HasFactory<DriverFeeLedgerFactory> */
    use HasFactory, HasUlids, IsAppendOnly;

    // The ERD names this table singular — Eloquent would otherwise guess
    // "driver_fee_ledgers" (see RAFEEQ_PROGRESS.md standard #21).
    protected $table = 'driver_fee_ledger';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => DriverFeeLedgerType::class,
            'amount_piastres' => 'integer',
            'balance_after_piastres' => 'integer',
        ];
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id', 'user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function settledFromPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'settled_from_payment_id');
    }
}
