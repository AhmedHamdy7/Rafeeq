<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\DevicePlatform;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'device_public_id', 'platform', 'device_model', 'os_version',
    'app_version', 'push_token', 'is_trusted', 'has_local_pin', 'biometric_enabled',
])]
#[Hidden(['push_token'])]
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'push_token' => 'encrypted',
            'is_trusted' => 'boolean',
            'has_local_pin' => 'boolean',
            'biometric_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
