<?php

namespace App\Domains\Admin\Models;

use App\Domains\Admin\Enums\AdminStatus;
use Database\Factories\AdminUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Authenticates the Livewire admin dashboard (decision D4) via the `web`
 * guard + Laravel session — a completely separate identity from the mobile
 * `User` model. MFA is mandatory for every admin (Bible §6.2).
 */
#[Fillable(['name', 'email', 'mfa_secret'])]
#[Hidden(['password_hash', 'mfa_secret', 'remember_token'])]
class AdminUser extends Authenticatable
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory, HasRoles, HasUlids;

    /**
     * Explicit, not auto-detected: Spatie resolves the guard from
     * config('auth.guards') by matching this model to a provider, which is
     * fragile across environments. Pin it directly instead.
     */
    protected string $guard_name = 'admin';

    protected function casts(): array
    {
        return [
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'status' => AdminStatus::class,
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The Bible names the column `password_hash`, not Laravel's default
     * `password` — Authenticatable needs to be told where to look.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function hasMfaConfirmed(): bool
    {
        return $this->mfa_confirmed_at !== null;
    }

    public function isActive(): bool
    {
        return $this->status === AdminStatus::Active;
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AdminAction::class, 'admin_id');
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'assigned_admin_id');
    }
}
