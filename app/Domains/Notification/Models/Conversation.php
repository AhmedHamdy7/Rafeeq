<?php

namespace App\Domains\Notification\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Notification\Enums\ConversationStatus;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat thread, identified by the ONE thing it is about: a booking, a seat request, or a group
 * (Chapter 11, extended by Feature 03 v5's "chat scoped to request/booking/waiting-list").
 *
 * Three nullable context columns rather than three tables, with a unique index on each: MySQL
 * permits many NULLs in a unique index, so one table holds every kind of thread with exactly one
 * row per thing.
 */
#[Fillable(['booking_id', 'seat_request_id', 'commute_group_id', 'opened_at'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'status' => ConversationStatus::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The seat request this thread is about, for a conversation that started before any seat
     * existed (Feature 03 v5).
     */
    public function seatRequest(): BelongsTo
    {
        return $this->belongsTo(SeatRequest::class);
    }

    public function commuteGroup(): BelongsTo
    {
        return $this->belongsTo(CommuteGroup::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
