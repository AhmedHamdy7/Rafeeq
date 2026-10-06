<?php

namespace App\Domains\Admin\Models;

use App\Domains\Shared\Concerns\IsAppendOnly;
use Database\Factories\AdminActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔒 INSERT-only — in production the app's DB grant is SELECT+INSERT only
 * on this table (Bible §6.2). Retention: 24 months.
 */
#[Fillable(['admin_id', 'action', 'entity_type', 'entity_id', 'old_value', 'new_value', 'reason', 'ip_hash', 'user_agent_hash'])]
class AdminAction extends Model
{
    /** @use HasFactory<AdminActionFactory> */
    use HasFactory, HasUlids, IsAppendOnly, MassPrunable;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    /**
     * Deleted by the scheduled `model:prune` after 24 months (ERD §18; confirmed 2026-10-06).
     *
     * A query delete, so the append-only guard above — which stops the APPLICATION deleting a row —
     * is not in the way of the one process allowed to. Where production applies the Bible's
     * `GRANT SELECT, INSERT` on this table, the scheduler must connect with a user that also holds
     * DELETE on it (DEPLOYMENT.md).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths((int) config('rafeeq.retention.admin_actions_months')));
    }
}
