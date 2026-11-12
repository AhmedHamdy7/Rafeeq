<?php

namespace App\Domains\Admin\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Concerns\IsAppendOnly;
use Database\Factories\MemberNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team note on a member (see the migration). Internal and append-only.
 */
#[Fillable(['user_id', 'admin_id', 'body'])]
class MemberNote extends Model
{
    /** @use HasFactory<MemberNoteFactory> */
    use HasFactory, HasUlids, IsAppendOnly;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }
}
