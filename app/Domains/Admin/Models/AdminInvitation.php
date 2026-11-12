<?php

namespace App\Domains\Admin\Models;

use Database\Factories\AdminInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time first-sign-in link (see the migration). Usable while not accepted, not revoked
 * and not expired — {@see isUsable()} is the one place that says so.
 */
#[Fillable(['admin_user_id', 'token_hash', 'invited_by_admin_id', 'expires_at'])]
class AdminInvitation extends Model
{
    /** @use HasFactory<AdminInvitationFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'invited_by_admin_id');
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The usable invitation behind a token from a link, or null. */
    public static function findUsable(string $token): ?self
    {
        $invitation = self::query()->with('admin')->where('token_hash', self::hash($token))->first();

        return $invitation?->isUsable() ? $invitation : null;
    }
}
