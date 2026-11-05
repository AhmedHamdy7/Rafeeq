<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Jobs\RecomputeTripDeparture;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\ComputedDeparture;
use App\Domains\Commute\Support\ScheduleLock;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves one day's departure when its riders change (C-2, COM-15, decision C9).
 *
 * Each time a rider's pickup or drop-off joins or leaves the day, RAFEEQ times the route again for
 * the promised arrival and, if the driver has to leave at a different minute, moves the day and
 * tells him — "Leave at 07:10 tomorrow". It never runs inside the transaction that confirms the
 * seat (it calls a routing provider): {@see RecomputeTripDeparture}
 * runs it after that commit.
 *
 * 🔒 Three things leave a day alone:
 *
 * - a day whose departure the driver fixed himself — only arrival-only days are RAFEEQ's to move;
 * - a day that is no longer `scheduled` — a run under way or cancelled has no departure to move;
 * - a day inside the 16-hour lock (COM-16): from then on the departure is a commitment, frozen
 *   with everything else, and a rider confirmed inside the lock was already checked against it
 *   ({@see ScheduleLock::assertNewRiderKeepsThePromise()}).
 */
final readonly class RecomputeDepartureAction
{
    public function __construct(private ComputedDeparture $departure) {}

    /**
     * @return bool whether the departure moved
     */
    public function execute(string $scheduledTripId): bool
    {
        $trip = ScheduledTrip::query()
            ->with(['commuteOffer.weekdaySchedules', 'commuteOffer.driverProfile.user', 'commuteSchedule'])
            ->whereKey($scheduledTripId)
            ->first();

        if ($trip === null
            || $trip->status !== ScheduledTripStatus::Scheduled
            || $trip->promised_arrival_at === null
            || ScheduleLock::isLocked($trip)) {
            return false;
        }

        $weekday = $trip->commuteOffer?->weekdaySchedules
            ->firstWhere('weekday_bit', DaysMask::bitForDate(CarbonImmutable::parse($trip->trip_date)));

        if ($weekday === null || ! $weekday->departure_is_computed) {
            return false;
        }

        $computed = $this->departure->forTrip($trip);

        if ($computed === null || $computed->equalTo($trip->departure_at)) {
            return false;
        }

        $timezone = $trip->commuteSchedule?->timezone ?? 'Africa/Cairo';
        $local = $computed->setTimezone($timezone);
        $movedEarlier = $computed->lessThan($trip->departure_at);

        DB::transaction(function () use ($trip, $computed, $local, $movedEarlier): void {
            $trip->forceFill([
                'departure_at' => $computed,
                'departure_local' => $local->format('Y-m-d H:i:s'),
            ])->save();

            $driver = $trip->commuteOffer?->driverProfile?->user;

            if ($driver !== null) {
                Notifier::send($driver, NotificationType::DepartureTimeChanged, [
                    'time' => $local->format('H:i'),
                    'date' => $local->format('Y-m-d'),
                ], [
                    'scheduledTripId' => (string) $trip->getKey(),
                    'commuteId' => (string) $trip->commute_offer_id,
                ]);
            }

            if ($movedEarlier) {
                $this->tellPassengers($trip, $local);
            }
        });

        return true;
    }

    private function tellPassengers(ScheduledTrip $trip, CarbonImmutable $local): void
    {
        Booking::query()
            ->with('passenger')
            ->where('scheduled_trip_id', $trip->getKey())
            ->where('status', BookingStatus::Confirmed->value)
            ->get()
            ->each(function (Booking $booking) use ($trip, $local): void {
                if ($booking->passenger === null) {
                    return;
                }

                Notifier::send($booking->passenger, NotificationType::PickupMovedEarlier, [
                    'time' => $local->format('H:i'),
                    'date' => $local->format('Y-m-d'),
                ], [
                    'bookingId' => (string) $booking->getKey(),
                    'scheduledTripId' => (string) $trip->getKey(),
                ]);
            });
    }
}
