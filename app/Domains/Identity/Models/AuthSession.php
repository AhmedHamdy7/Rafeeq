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
use Laravel\Sanctum\PersonalAccessToken;

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

    public function isRefreshExpired(): bool
    {
        return $this->refresh_expires_at->isPast();
    }

    /**
     * Revoking a session has to kill BOTH halves or it is theatre: marking
     * the row revoked stops future refreshes, but the access token already
     * in the attacker's hands would keep working until it expired on its
     * own. Sanctum tokens are named after the session id precisely so this
     * can find them (scenario G).
     */
    public function revoke(SessionRevocationReason $reason): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill([
                'revoked_at' => now(),
                'revocation_reason' => $reason->value,
            ])->save();
        }

        // Session ids are ULIDs, so the name is globally unique — no need to
        // scope by user, and no lazy-loaded relation to trip strict mode.
        PersonalAccessToken::where('name', $this->id)->delete();
    }
}
