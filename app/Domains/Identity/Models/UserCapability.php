<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\CapabilityStatus;
use Database\Factories\UserCapabilityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per thing this account is enrolled to do. See the migration for why this exists
 * alongside `users.registered_role` rather than replacing it.
 *
 * No `$fillable`: every column is written by `EnrollInitialRoleAction`, never from a request body.
 * A mass-assignable `status` here would let a client withdraw — or silently re-activate — a
 * capability by including a field in an unrelated payload.
 */
class UserCapability extends Model
{
    /** @use HasFactory<UserCapabilityFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'capability' => Capability::class,
            'status' => CapabilityStatus::class,
            'enrolled_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 🔴 Written as a scope rather than left to each caller, because "which capabilities does this
     * person have" is asked by the launch router, the driver middleware and the profile payload,
     * and all three must agree. A caller that forgets the status filter sees a withdrawn
     * capability as a live one.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CapabilityStatus::Active->value);
    }
}
