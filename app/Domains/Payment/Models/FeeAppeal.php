<?php

namespace App\Domains\Payment\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Models\User;
use App\Domains\Trip\Enums\DisputeResolution;
use Database\Factories\FeeAppealFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver's appeal against one of his fees. See the migration.
 *
 * No `$fillable`: the outcome is written by staff through `ResolveFeeAppealAction`, never from a
 * request.
 */
class FeeAppeal extends Model
{
    /** @use HasFactory<FeeAppealFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'resolution' => DisputeResolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function debtEntry(): BelongsTo
    {
        return $this->belongsTo(DebtEntry::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'resolved_by_admin_id');
    }
}
