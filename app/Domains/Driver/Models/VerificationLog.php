<?php

namespace App\Domains\Driver\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Driver\Enums\VerificationLogAction;
use App\Domains\Shared\Concerns\IsAppendOnly;
use Database\Factories\VerificationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔒 Permanent legal record of every verification decision — never updated
 * or deleted (Bible §4, group ③).
 */
#[Fillable(['entity_type', 'entity_id', 'admin_id', 'action', 'old_value', 'new_value', 'reason'])]
class VerificationLog extends Model
{
    /** @use HasFactory<VerificationLogFactory> */
    use HasFactory, HasUlids, IsAppendOnly;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => VerificationLogAction::class,
            'old_value' => 'array',
            'new_value' => 'array',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }
}
