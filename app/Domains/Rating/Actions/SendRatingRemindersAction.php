<?php

namespace App\Domains\Rating\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Models\Notification;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Rating\Models\Rating;
use App\Domains\Rating\Support\RatingSettings;

/**
 * "Last day to rate your trip" — one reminder, in the final 24 hours of the rating window.
 *
 * 🔴 Why this exists rather than only the prompt at completion: the double-blind design reveals a
 * rating when both have rated OR the window closes. Somebody who never rates leaves the other
 * side's rating hidden until the very end, and the quietest way to keep a bad review off your
 * profile is never to write one. A nudge before the deadline is the honest counterweight.
 *
 * Exactly one per person per booking (passenger) or per run (driver) — checked against the inbox
 * itself, so the hourly schedule can run as often as it likes. Nobody is reminded about a booking
 * they already rated.
 */
final readonly class SendRatingRemindersAction
{
    public function execute(): int
    {
        $windowDays = RatingSettings::windowDays();
        $sent = 0;

        // Bookings whose window closes within the next 24 hours: departure + window ∈ (now, now+24h].
        $closingSoon = Booking::query()
            ->where('status', BookingStatus::Completed->value)
            ->whereHas('scheduledTrip', fn ($trip) => $trip
                ->where('departure_at', '>', now()->subDays($windowDays))
                ->where('departure_at', '<=', now()->subDays($windowDays)->addDay()))
            ->with(['passenger', 'scheduledTrip.commuteOffer.driverProfile.user'])
            ->get();

        foreach ($closingSoon as $booking) {
            if (! $this->hasRated($booking->passenger_user_id, $booking->id)
                && ! $this->alreadyReminded($booking->passenger, 'bookingId', $booking->id)) {
                Notifier::send($booking->passenger, NotificationType::RatingReminder, data: ['bookingId' => $booking->id]);
                $sent++;
            }
        }

        // The driver: once per run, if any rider on it is still unrated.
        foreach ($closingSoon->groupBy('scheduled_trip_id') as $tripId => $bookings) {
            $driver = $bookings->first()->scheduledTrip->commuteOffer->driverProfile->user;

            $unrated = $bookings->contains(fn (Booking $booking) => ! $this->hasRated($driver->id, $booking->id));

            if ($unrated && ! $this->alreadyReminded($driver, 'tripId', $tripId)) {
                Notifier::send($driver, NotificationType::RatingReminder, data: ['tripId' => $tripId]);
                $sent++;
            }
        }

        return $sent;
    }

    private function hasRated(string $userId, string $bookingId): bool
    {
        return Rating::query()->where('booking_id', $bookingId)->where('reviewer_user_id', $userId)->exists();
    }

    private function alreadyReminded(User $user, string $key, string $id): bool
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('type', NotificationType::RatingReminder->value)
            ->where('channel', NotificationChannel::InApp->value)
            ->where("data->{$key}", $id)
            ->exists();
    }
}
