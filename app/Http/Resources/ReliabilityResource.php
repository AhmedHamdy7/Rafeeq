<?php

namespace App\Http\Resources;

use App\Domains\Reliability\Enums\ReliabilityRole;
use App\Domains\Reliability\Support\ReliabilityLedger;
use App\Domains\Reliability\Support\ReliabilitySettings;

/**
 * Somebody's reliability standing, as she reads it about herself (Feature 03 v5).
 *
 * 🔴 **The score never travels without the line items.** v5 routes a low score to a monthly admin
 * review, a temporary suspension and a permanent ban — so a bare number would be a thing done to
 * somebody with nothing she could check it against. Every event behind it is listed, with what it
 * was and when, which is also what an appeal is argued from.
 *
 * 🔒 **Hers alone, and deliberately not on a public profile.** v5 asks for this number in the
 * monthly review; it does not ask for it to be shown to strangers deciding whether to ride with
 * her, and publishing a new judgement about somebody is a product decision rather than a gap to
 * close quietly. The match score already has its own punctuality component for the search.
 *
 * ⚠️ Reversed events ARE listed, marked. That she appealed and won is the part a second appeal and
 * a reinstatement both turn on, so hiding it would erase the most favourable thing on her record.
 */
final class ReliabilityResource
{
    /**
     * @return array<string, mixed>
     */
    public static function for(string $userId): array
    {
        return [
            'asDriver' => self::forRole($userId, ReliabilityRole::Driver),
            'asPassenger' => self::forRole($userId, ReliabilityRole::Passenger),

            /*
             * Published so the number is readable rather than mysterious: "100 out of 100 over the
             * last 180 days" is a statement somebody can act on, and "100" alone is not.
             */
            'windowDays' => ReliabilitySettings::windowDays(),
            'perfectScore' => ReliabilityLedger::PERFECT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function forRole(string $userId, ReliabilityRole $role): array
    {
        $events = ReliabilityLedger::historyFor($userId, $role);

        /*
         * A foreach rather than a mapped collection: a mapped array loses its element type to the
         * OpenAPI generator (standard #37) and the mobile team cannot generate a client from a list
         * of `mixed`.
         */
        $items = [];

        foreach ($events as $event) {
            $items[] = [
                'id' => (string) $event->getKey(),
                'kind' => strtoupper($event->kind->value),
                'weight' => $event->weight,
                'occurredAt' => $event->occurred_at->toIso8601String(),

                // What it was about, so she can find the morning in question.
                'bookingId' => $event->booking_id,
                'tripId' => $event->scheduled_trip_id,
                'commuteId' => $event->commute_offer_id,

                /*
                 * Whether it still counts. Named rather than left to be inferred from
                 * `reversedAt`, because the client's behaviour differs — a reversed event is shown
                 * struck through rather than hidden — and inferring it would have every client
                 * re-derive the rule.
                 */
                'counts' => $event->counts(),
                'reversedAt' => $event->reversed_at?->toIso8601String(),
                'reversedReason' => $event->reversed_reason,
            ];
        }

        return [
            'score' => ReliabilityLedger::scoreFor($userId, $role),
            'events' => $items,
        ];
    }
}
