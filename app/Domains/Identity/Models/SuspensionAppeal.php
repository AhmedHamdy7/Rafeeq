<?php

namespace App\Domains\Identity\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Trip\Enums\DisputeResolution;
use Database\Factories\SuspensionAppealFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A suspended member's appeal against her hold (AUTH-19, follow-up F1-3). See the migration.
 *
 * No `$fillable`: she writes it through `AppealSuspensionAction`, staff decide it through
 * `ResolveSuspensionAppealAction`, and neither takes a column from a request.
 */
class SuspensionAppeal extends Model
{
    /** @use HasFactory<SuspensionAppealFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'resolution' => DisputeResolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function suspension(): BelongsTo
    {
        return $this->belongsTo(AccountSuspension::class, 'account_suspension_id');
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
