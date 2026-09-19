<?php

namespace App\Domains\Safety\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Shared\Concerns\PreventsDeletion;
use App\Domains\Trip\Models\TripSession;
use Database\Factories\SafetyEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 🔒 NEVER DELETE (Bible §4, group ⑫) — real evidence for a real
 * investigation. Updates ARE allowed (e.g. `reviewed_at`-style admin
 * follow-up) — unlike `verification_logs`/`booking_events`/
 * `driver_fee_ledger`, this table isn't documented as INSERT-only.
 */
#[Fillable(['type', 'user_id', 'trip_session_id', 'booking_id', 'severity', 'metadata', 'occurred_at'])]
class SafetyEvent extends Model
{
    /** @use HasFactory<SafetyEventFactory> */
    use HasFactory, HasUlids, PreventsDeletion;

    protected function casts(): array
    {
        return [
            'type' => SafetyEventType::class,
            'severity' => SafetySeverity::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tripSession(): BelongsTo
    {
        return $this->belongsTo(TripSession::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function sosEvent(): HasOne
    {
        return $this->hasOne(SosEvent::class);
    }
}
