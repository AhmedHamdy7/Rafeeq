<?php

namespace App\Domains\Notification\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationCategory;
use App\Domains\Notification\Enums\NotificationChannel;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rafeeq's own notification log — not Laravel's built-in `DatabaseNotification`
 * (different schema: this has `channel`/`category`/`title`/`body` as required
 * columns). Sending a notification via `$user->notify(...)` with the
 * built-in `database` channel would NOT write here; Phase 12 needs either a
 * custom channel or a dedicated send Action targeting this model directly.
 */
#[Fillable(['user_id', 'type', 'title', 'body', 'data', 'channel', 'category'])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'channel' => NotificationChannel::class,
            'category' => NotificationCategory::class,
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
