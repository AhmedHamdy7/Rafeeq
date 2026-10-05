<?php

namespace App\Domains\Notification\Models;

use App\Domains\Identity\Models\User;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['conversation_id', 'sender_user_id', 'body'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids, MassPrunable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'contains_contact_info' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function isFlagged(): bool
    {
        return $this->flagged_reason !== null;
    }

    /**
     * Deleted by the scheduled `model:prune`. Trip chat is for the trip (Chapter 11). A report about a message copies its text into the case, so pruning here never erases evidence.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths((int) config('rafeeq.retention.messages_months')));
    }
}
