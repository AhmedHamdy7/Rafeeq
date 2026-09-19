<?php

namespace App\Domains\Admin\Models;

use App\Domains\Shared\Concerns\IsAppendOnly;
use Database\Factories\AdminActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    use HasFactory, HasUlids, IsAppendOnly;

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
}
