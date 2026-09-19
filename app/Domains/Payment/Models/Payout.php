<?php

namespace App\Domains\Payment\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Payment\Enums\PayoutMethod;
use App\Domains\Payment\Enums\PayoutStatus;
use Database\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks Paymob's transfers to drivers — Rafeeq holds no custodial balance
 * (decision D15), so this is reporting/reconciliation, not a ledger.
 */
#[Fillable(['driver_profile_id', 'period_start', 'period_end', 'amount_piastres', 'method'])]
class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount_piastres' => 'integer',
            'method' => PayoutMethod::class,
            'status' => PayoutStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id', 'user_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'released_by');
    }
}
