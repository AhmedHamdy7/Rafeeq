<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\SessionRevocationReason;
use Database\Factories\AuthSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refresh-token rotation chain (Bible §4, group ①). Table is named
 * `auth_sessions`, not `sessions` — see RAFEEQ_PROGRESS.md for why.
 */
#[Fillable([
    'user_id', 'device_id', 'refresh_token_hash', 'token_family_id',
    'previous_session_id', 'access_expires_at', 'refresh_expires_at',
])]
#[Hidden(['refresh_token_hash'])]
class AuthSession extends Model
{
    /** @use HasFactory<AuthSessionFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'access_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'revocation_reason' => SessionRevocationReason::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function previousSession(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_session_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
