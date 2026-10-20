<?php

namespace App\Domains\Commute\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use Carbon\CarbonImmutable;

/**
 * The hours before a departure when the day stops changing (Feature 03 v5).
 *
 * > *"**16 hours before planned departure**, route, departure and pickup schedule become locked.
 * > New bookings after lock only when compatible with frozen commitments."*
 *
 * 🔴 **Two thirds of this was already true, and more strictly than v5 asks.** A published commute's
 * route and schedule cannot be edited at all in this codebase — `CommuteState::assertEditable()`
 * allows it only on a DRAFT, because people book specific days on the strength of both. So there is
 * no "16 hours before" for those: they are frozen from the moment the commute goes up. This class
 * is not about them.
 *
 * What it governs is the third thing, and the one new rule:
 *
 * 1. **The pickup schedule.** An approved pickup-point change DOES move an agreed meeting place on
 *    every future day of a published commute, and that is the one edit that can still land inside
 *    the window. Tomorrow morning's kerb stops moving sixteen hours before she stands on it.
 * 2. **New bookings must be compatible with the frozen commitments.** Inside the window, a seat is
 *    only given to somebody whose two points do not push the day's promised arrival past what the
 *    people already on board were promised.
 *
 * ⚠️ Rule 2 applies INSIDE the window only, and that is deliberate rather than lax. Before the
 * lock, nothing is frozen — the driver's own `max_detour_minutes` is what protects her, and
 * refusing a booking three weeks out because a straight-line estimate said two minutes late would
 * turn an estimate into a promise. The lock is the moment estimates become commitments.
 */
final readonly class ScheduleLock
{
    /**
     * How many hours before departure the day stops changing.
     *
     * Configurable because it is a policy number, not a technical one (standard #11): sixteen hours
     * is a choice about how much notice a woman needs to rearrange her morning, and it may move.
     */
    public static function hours(): int
    {
        return (int) config('rafeeq.commute.schedule_lock_hours');
    }

    /**
     * When this day's arrangements freeze.
     */
    public static function lockedFrom(ScheduledTrip $trip): ?CarbonImmutable
    {
        if ($trip->departure_at === null) {
            return null;
        }

        return CarbonImmutable::parse($trip->departure_at)->subHours(self::hours());
    }

    public static function isLocked(ScheduledTrip $trip): bool
    {
        $from = self::lockedFrom($trip);

        return $from !== null && $from->isPast();
    }

    /**
     * Refuse an edit to a day whose arrangements have frozen.
     *
     * 🔒 Only when somebody is actually relying on them. A locked day with no confirmed passenger
     * has nobody to protect, and refusing an edit on it would stop a driver tidying her own empty
     * morning for no one's benefit — the lock exists for the people who have planned around it.
     */
    public static function assertNotLocked(ScheduledTrip $trip): void
    {
        if (! self::isLocked($trip)) {
            return;
        }

        $relied = Booking::query()
            ->where('scheduled_trip_id', $trip->getKey())
            ->where('status', BookingStatus::Confirmed->value)
            ->exists();

        if (! $relied) {
            return;
        }

        throw DomainException::of(ErrorCode::ScheduleLocked, fields: [
            'lockedFrom' => [(string) self::lockedFrom($trip)?->toIso8601String()],
            'lockHours' => [(string) self::hours()],
        ]);
    }

    /**
     * Whether one more passenger's two points can be added to a locked day without breaking the
     * arrival the people already on board were promised.
     *
     * 🔴 Called OUTSIDE the transaction that allocates the seat, always. Composing an itinerary
     * calls the routing provider once per leg, and the seat allocation holds a row lock on
     * `seats_taken` — the busiest lock in the system. A provider's latency inside it would stop
     * people booking, which is the same reasoning
     * {@see BuildNavigationItineraryAction} sets out for not rebuilding
     * itineraries there.
     *
     * ⚠️ So there is a small race: two seats confirmed in the same instant are each judged against
     * an itinerary without the other. The consequence is one extra rider on a day near its limit,
     * which the detour ceiling still bounds; the alternative is a third party's network inside the
     * seat lock. That trade is deliberate.
     *
     * Silent when the day carries no promise. The principle is the one `CompleteTripAction` already
     * works to: no promise, no breach. Nothing is scored, or refused, against null.
     */
    public static function assertNewRiderKeepsThePromise(
        BuildNavigationItineraryAction $itinerary,
        ScheduledTrip $trip,
        Coordinate $pickup,
        ?string $pickupLabel,
        Coordinate $dropoff,
        ?string $dropoffLabel,
    ): void {
        if (! self::isLocked($trip) || $trip->promised_arrival_at === null) {
            return;
        }

        /*
         * The preview path, which composes the day WITH this person's points and persists nothing.
         * Using it rather than a bespoke calculation means the figure refused on is the same figure
         * the driver was shown when she looked at the request.
         */
        $proposed = $itinerary->preview($trip, $pickup, $pickupLabel, $dropoff, $dropoffLabel);

        $arrival = CarbonImmutable::parse($trip->departure_at)->addSeconds($proposed->durationSeconds);

        if ($arrival->lessThanOrEqualTo(CarbonImmutable::parse($trip->promised_arrival_at))) {
            return;
        }

        throw DomainException::of(ErrorCode::BookingBreaksFrozenPromise, fields: [
            'promisedArrivalAt' => [(string) CarbonImmutable::parse($trip->promised_arrival_at)->toIso8601String()],
            'projectedArrivalAt' => [(string) $arrival->toIso8601String()],
            'lockedFrom' => [(string) self::lockedFrom($trip)?->toIso8601String()],
        ]);
    }
}
