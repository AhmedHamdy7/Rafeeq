<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\NotificationSettings;
use App\Domains\Notification\Support\Notifier;
use Carbon\CarbonImmutable;

/**
 * "Your commute leaves in an hour" and "…in 10 minutes" (Feature 03 files §8), to the driver and
 * every confirmed passenger.
 *
 * 🔴 **Each reminder fires once per day, recorded in a column** — the same shape as the delay
 * ladder ({@see SweepDelayedTripsAction}) and for the same reason: this runs every minute, so
 * "already reminded" has to be a fact, not something recomputed from an inbox that retention
 * prunes.
 *
 * 🔴 **Only days somebody booked.** A driver whose Thursday nobody booked is not reminded to drive
 * it: the reminder exists because people are waiting on her, and a buzz about an empty car teaches
 * her to ignore the one that matters.
 *
 * When both are due at once — a seat confirmed eight minutes before departure, a cron that was off
 * — only the final reminder is sent and both are stamped. Two buzzes a minute apart about the same
 * departure is noise.
 *
 * Only days still `scheduled`: once the run is under way the driver started it, and the passengers
 * are told that instead ({@see NotificationType::TripStarted}).
 */
final readonly class SendTripRemindersAction
{
    /**
     * @return array{early: int, final: int}
     */
    public function sweep(): array
    {
        $counts = ['early' => 0, 'final' => 0];

        $now = CarbonImmutable::now();
        $early = NotificationSettings::tripReminderEarlyMinutes();
        $final = NotificationSettings::tripReminderFinalMinutes();

        $due = ScheduledTrip::query()
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->whereNotNull('departure_at')
            ->where('departure_at', '>', $now)
            ->where('departure_at', '<=', $now->addMinutes(max($early, $final)))
            ->where(fn ($query) => $query
                ->whereNull('trip_reminder_early_sent_at')
                ->orWhereNull('trip_reminder_final_sent_at'))
            ->with('commuteOffer.driverProfile.user')
            ->orderBy('departure_at')
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($due as $trip) {
            $minutesLeft = $now->diffInMinutes($trip->departure_at, absolute: true);

            if ($minutesLeft <= $final) {
                // Claimed with a conditional update so two overlapping runs cannot both send it.
                if ($this->claim($trip, 'trip_reminder_final_sent_at', $now)) {
                    $this->remind($trip, NotificationType::TripStartsSoon, $final);
                    $counts['final']++;
                }

                // The hour reminder is now pointless; mark it so it is never sent late.
                $this->claim($trip, 'trip_reminder_early_sent_at', $now);

                continue;
            }

            if ($minutesLeft <= $early && $this->claim($trip, 'trip_reminder_early_sent_at', $now)) {
                $this->remind($trip, NotificationType::TripStartsInHour, $early);
                $counts['early']++;
            }
        }

        return $counts;
    }

    private function claim(ScheduledTrip $trip, string $column, CarbonImmutable $now): bool
    {
        return ScheduledTrip::query()
            ->whereKey($trip->getKey())
            ->whereNull($column)
            ->update([$column => $now]) === 1;
    }

    private function remind(ScheduledTrip $trip, NotificationType $type, int $minutes): void
    {
        $bookings = Booking::query()
            ->where('scheduled_trip_id', $trip->getKey())
            ->where('status', BookingStatus::Confirmed->value)
            ->with('passenger')
            ->orderBy('id')
            ->get();

        if ($bookings->isEmpty()) {
            return;
        }

        $params = [
            'time' => $trip->departure_local?->format('H:i') ?? '',
            'minutes' => (string) $minutes,
        ];

        $driver = $trip->commuteOffer?->driverProfile?->user;

        if ($driver instanceof User) {
            Notifier::send($driver, $type, params: $params, data: [
                'tripId' => (string) $trip->getKey(),
                'commuteId' => (string) $trip->commute_offer_id,
            ]);
        }

        foreach ($bookings as $booking) {
            if ($booking->passenger instanceof User) {
                Notifier::send($booking->passenger, $type, params: $params, data: [
                    'bookingId' => (string) $booking->getKey(),
                    'tripId' => (string) $trip->getKey(),
                ]);
            }
        }
    }
}
