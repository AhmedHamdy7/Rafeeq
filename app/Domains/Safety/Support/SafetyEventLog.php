<?php

namespace App\Domains\Safety\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\SafetyEvent;
use App\Domains\Trip\Models\TripSession;

/**
 * 🔒 One writer for `safety_events` — the table that is never deleted.
 *
 * Every safety act on the platform passes through here: an SOS, a silent alert, a live share, a
 * report. The point of a single writer is not tidiness. It is that these rows are what somebody
 * reads months later, possibly in a police station, and the three things most easily forgotten
 * at a call site are the severity, the moment it actually happened, and the link back to the
 * trip it happened on. A row missing any of those is a row that proves nothing.
 *
 * 🔴 `occurred_at` is the moment of the EVENT, not of the insert, and that distinction is the
 * reason the column exists. An SOS pressed in a tunnel is recorded when the request finally
 * arrives, which can be minutes later — and "when did she press it" is the first question anybody
 * will ask. The caller passes what it knows; nothing here guesses.
 *
 * What is deliberately NOT stored in `metadata`: a phone number, a home address, a token, the
 * contents of a message. A safety row says what happened, to whom, on which trip. A row that also
 * carried the sensitive details would turn the one table nobody may delete into a permanent copy
 * of them.
 */
final class SafetyEventLog
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        SafetyEventType $type,
        User $user,
        SafetySeverity $severity,
        ?TripSession $tripSession = null,
        ?Booking $booking = null,
        array $metadata = [],
        ?\DateTimeInterface $occurredAt = null,
    ): SafetyEvent {
        return SafetyEvent::query()->create([
            'type' => $type->value,
            'user_id' => $user->id,
            'trip_session_id' => $tripSession?->id,
            'booking_id' => $booking?->id,
            'severity' => $severity->value,
            'metadata' => self::safeMetadata($metadata),
            /*
             * The caller's moment when it has one, ours only as a fallback. See the class note:
             * an SOS pressed in a tunnel arrives late, and the difference matters.
             */
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * 🔒 Strips the keys that must never end up in a table nobody may delete.
     *
     * A guard rather than a convention, because `metadata` is a JSON column and the pressure to
     * put "just one more useful field" in it only ever goes one way. The list is the sensitive
     * data this platform holds; anything matching is dropped and the key is replaced with a
     * marker, so a reader can see something was removed rather than wondering whether it was
     * ever sent.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private static function safeMetadata(array $metadata): array
    {
        $forbidden = ['phone', 'phone_e164', 'email', 'token', 'national_id', 'licence_number',
            'password', 'access_token', 'refresh_token', 'push_token', 'address'];

        $clean = [];

        foreach ($metadata as $key => $value) {
            $matches = false;

            foreach ($forbidden as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    $matches = true;

                    break;
                }
            }

            $clean[$key] = $matches ? '[removed]' : $value;
        }

        return $clean;
    }
}
