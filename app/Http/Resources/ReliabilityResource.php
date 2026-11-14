<?php

namespace App\Http\Resources;

use App\Domains\Reliability\Enums\ReliabilityRole;
use App\Domains\Reliability\Models\ReliabilityAppealEvidence;
use App\Domains\Reliability\Support\EventFee;
use App\Domains\Reliability\Support\ReliabilityAppeals;
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
        $events->load('appeals', 'evidence', 'booking.passenger');

        $fees = $role === ReliabilityRole::Driver ? EventFee::forEvents($events) : [];

        $items = [];

        foreach ($events as $event) {
            $refusal = ReliabilityAppeals::refusal($event);
            $last = ReliabilityAppeals::last($event);
            $left = ReliabilityAppeals::left($event);
            $evidence = [];

            foreach ($event->evidence->sortBy('id') as $file) {
                $evidence[] = self::evidenceItem($file);
            }

            $fee = $fees[(string) $event->getKey()] ?? null;
            $passenger = $role === ReliabilityRole::Driver ? $event->booking?->passenger : null;

            $items[] = [
                'id' => (string) $event->getKey(),
                'kind' => strtoupper($event->kind->value),
                'weight' => $event->weight,
                'occurredAt' => $event->occurred_at->toIso8601String(),
                'bookingId' => $event->booking_id,
                'tripId' => $event->scheduled_trip_id,
                'commuteId' => $event->commute_offer_id,
                'cause' => $event->cause?->value,
                'groupId' => $event->group_id,
                'cancelReason' => $event->cancel_reason,
                'person' => $passenger === null ? null : [
                    'userId' => (string) $passenger->getKey(),
                    'publicFirstName' => $passenger->public_first_name,
                ],
                'counts' => $event->counts(),
                'reversedAt' => $event->reversed_at?->toIso8601String(),
                'reversedReason' => null,
                'canAppeal' => $refusal === null,
                'appealsLeft' => $left,
                'needsNewEvidence' => $last !== null && $left > 0,
                'appealBy' => ReliabilityAppeals::appealableUntil($event)?->toIso8601String(),
                'appealRefusal' => $refusal,
                'evidence' => $evidence,
                'appeal' => $last === null ? null : [
                    'id' => (string) $last->getKey(),
                    'round' => $last->round,
                    'status' => $last->status(),
                    'appealedAt' => $last->created_at?->toIso8601String(),
                    'decidedAt' => $last->resolved_at?->toIso8601String(),
                    'declineReasonCode' => $last->decline_reason_code?->value,
                ],
                'fee' => $fee === null ? null : [
                    'debtEntryId' => (string) $fee['debtEntryId'],
                    'amountPiastres' => (int) $fee['amountPiastres'],
                    'reversed' => (bool) $fee['reversed'],
                ],
            ];
        }

        return [
            'score' => ReliabilityLedger::scoreFor($userId, $role),
            'events' => $items,
        ];
    }

    public static function evidenceItem(ReliabilityAppealEvidence $evidence): array
    {
        return [
            'id' => (string) $evidence->getKey(),
            'kind' => $evidence->kind?->value,
            'purgeAfter' => $evidence->purge_after?->toDateString(),
            'createdAt' => $evidence->created_at->toIso8601String(),
        ];
    }
}
