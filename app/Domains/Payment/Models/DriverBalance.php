<?php

namespace App\Domains\Payment\Models;

use App\Domains\Driver\Models\DriverProfile;
use Database\Factories\DriverBalanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A computed projection of {@see DriverFeeLedger} kept for fast reads. A
 * daily job compares `outstanding_fee_piastres` against
 * `SUM(driver_fee_ledger.amount_piastres)` and alerts on drift — pitfall #39.
 */
#[Fillable(['driver_profile_id'])]
class DriverBalance extends Model
{
    /** @use HasFactory<DriverBalanceFactory> */
    use HasFactory;

    protected $primaryKey = 'driver_profile_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'outstanding_fee_piastres' => 'integer',
            'lifetime_earnings_piastres' => 'integer',
            'last_settled_at' => 'datetime',
            'is_blocked_from_publishing' => 'boolean',
            'reconciled_at' => 'datetime',
        ];
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id', 'user_id');
    }

    public function exceedsDebtCap(int $capPiastres): bool
    {
        return $this->outstanding_fee_piastres > $capPiastres;
    }
}
