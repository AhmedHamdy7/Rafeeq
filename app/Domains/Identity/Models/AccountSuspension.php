<?php

namespace App\Domains\Identity\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Enums\SuspensionReason;
use App\Domains\Safety\Models\Incident;
use App\Domains\Shared\Concerns\PreventsDeletion;
use Database\Factories\AccountSuspensionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time staff put an account on hold. See the migration for why it is its own table.
 *
 * No `$fillable`: every column is written by `SuspendMemberAction`, never from a request.
 * `PreventsDeletion` rather than append-only, because lifting a suspension is an update to
 * the row it ends.
 */
class AccountSuspension extends Model
{
    /** @use HasFactory<AccountSuspensionFactory> */
    use HasFactory, HasUlids, PreventsDeletion;

    protected function casts(): array
    {
        return [
            'reason_code' => SuspensionReason::class,
            'suspended_at' => 'datetime',
            'review_due_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'suspended_by_admin_id');
    }

    public function liftedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'lifted_by_admin_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeInForce(Builder $query): void
    {
        $query->whereNull('lifted_at');
    }

    public function isOverdue(): bool
    {
        return $this->lifted_at === null && $this->review_due_at->isPast();
    }
}
