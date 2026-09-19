<?php

namespace App\Domains\Safety\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Trip\Models\TripSession;
use Database\Factories\LiveShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pitfall #29: the token must be ≥32 random bytes, hashed at rest — never a
 * guessable sequential id.
 */
#[Fillable(['trip_session_id', 'user_id', 'token_hash', 'shared_with_contact_id', 'expires_at'])]
#[Hidden(['token_hash'])]
class LiveShare extends Model
{
    /** @use HasFactory<LiveShareFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'view_count' => 'integer',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function tripSession(): BelongsTo
    {
        return $this->belongsTo(TripSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sharedWithContact(): BelongsTo
    {
        return $this->belongsTo(EmergencyContact::class, 'shared_with_contact_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
