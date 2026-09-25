<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingEvent;

/**
 * Every status change a booking goes through, appended and never edited.
 *
 * This table answers "what exactly happened" when a booking is disputed — who
 * changed it, when, from what to what. It is INSERT-only, so the record cannot be
 * tidied up afterwards by whoever has an interest in how it reads.
 *
 * Written inside the same transaction as the change it describes, so a booking
 * and its history cannot disagree.
 */
final class BookingEventLog
{
    public static function record(
        Booking $booking,
        BookingEventType $event,
        BookingActorType $actorType,
        ?string $actorId = null,
        ?BookingStatus $from = null,
        array $metadata = [],
    ): BookingEvent {
        return BookingEvent::create([
            'booking_id' => $booking->id,
            'event_type' => $event->value,
            'actor_type' => $actorType->value,
            // Null for a system action, which is why the column is nullable: a
            // trip expiring has no actor to blame.
            'actor_id' => $actorId,
            'from_status' => $from?->value,
            'to_status' => $booking->status->value,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
