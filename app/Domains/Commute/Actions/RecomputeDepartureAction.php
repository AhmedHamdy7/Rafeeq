<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\PickupEta;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Jobs\RecomputeTripDeparture;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\ComputedDeparture;
use App\Domains\Commute\Support\ScheduleLock;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
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
    private const int MINIMUM_CHANGE_SECONDS = 60;

    public function __construct(
        private ComputedDeparture $departure,
        private GeoQueryEngine $geo,
        private BuildNavigationItineraryAction $itinerary,
    ) {}

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

        if ($computed === null || $computed->greaterThanOrEqualTo($trip->departure_at)) {
            return false;
        }

        $timezone = $trip->commuteSchedule?->timezone ?? 'Africa/Cairo';
        $local = $computed->setTimezone($timezone);
        $before = $this->pickups($trip);

        DB::transaction(function () use ($trip, $computed, $local): void {
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
        });

        $this->tellPassengers($trip->refresh(), $before);

        return true;
    }

    private function pickups(ScheduledTrip $trip): array
    {
        $times = [];

        foreach ($this->riders($trip) as $booking) {
            $times[$booking->getKey()] = PickupEta::forBooking($this->geo, $booking);
        }

        return $times;
    }

    private function riders(ScheduledTrip $trip): mixed
    {
        return Booking::query()
            ->with('passenger', 'scheduledTrip.commuteOffer.locations')
            ->where('scheduled_trip_id', $trip->getKey())
            ->where('status', BookingStatus::Confirmed->value)
            ->get();
    }

    private function tellPassengers(ScheduledTrip $trip, array $before): void
    {
        $this->itinerary->forTrip($trip);

        foreach ($this->riders($trip) as $booking) {
            $was = $before[$booking->getKey()] ?? null;
            $now = PickupEta::forBooking($this->geo, $booking);

            if ($booking->passenger === null || $was === null || $now === null || abs($was->diffInSeconds($now, false)) < self::MINIMUM_CHANGE_SECONDS) {
                continue;
            }

            $local = PickupEta::local($booking->scheduledTrip, $now);

            Notifier::send($booking->passenger, NotificationType::PickupTimeChanged, [
                'time' => substr((string) $local, 11, 5),
                'date' => $trip->trip_date->toDateString(),
            ], [
                'bookingId' => (string) $booking->getKey(),
                'scheduledTripId' => (string) $trip->getKey(),
                'pickupEta' => $now->toIso8601String(),
                'pickupEtaLocal' => $local,
            ]);
        }
    }
}
