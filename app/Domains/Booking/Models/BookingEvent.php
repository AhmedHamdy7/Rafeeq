<?php

namespace App\Domains\Booking\Models;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Shared\Concerns\IsAppendOnly;
use Database\Factories\BookingEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔒 INSERT-only — "what exactly happened" for disputes (Bible §4, group ⑦).
 */
#[Fillable(['booking_id', 'event_type', 'actor_type', 'actor_id', 'from_status', 'to_status', 'metadata'])]
class BookingEvent extends Model
{
    /** @use HasFactory<BookingEventFactory> */
    use HasFactory, HasUlids, IsAppendOnly;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'event_type' => BookingEventType::class,
            'actor_type' => BookingActorType::class,
            'metadata' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
