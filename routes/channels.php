<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| 🔒 The most security-sensitive file in the application, because a channel
| authorisation callback is the ONLY thing standing between a live feed of where
| identifiable people are and anybody who guessed an id. There is no second check
| after this: once a socket is subscribed, every event on that channel reaches it.
|
| So every callback here answers one question — "is this person ON this run right
| now?" — and returns a boolean rather than a payload. Returning an array would
| make the channel leak whoever it described to everyone subscribed.
|
*/

Broadcast::channel('trip.{tripSessionId}', function (User $user, string $tripSessionId): bool {
    $session = TripSession::query()
        ->whereKey($tripSessionId)
        ->with('scheduledTrip.commuteOffer')
        ->first();

    if ($session === null) {
        return false;
    }

    /*
     * 🔒 Only while the run is actually out on the road.
     *
     * A subscription that outlived the journey would keep a socket open onto a channel that
     * carries positions — and the next thing pushed to it would be wherever the driver drove
     * after everybody got out. Which, for a commute, is home.
     */
    if (! $session->current_status->isUnderway()) {
        return false;
    }

    // The driver, whose car it is.
    if ($session->scheduledTrip->commuteOffer->driver_profile_id === $user->id) {
        return true;
    }

    /*
     * Or somebody holding a live seat on this exact day. A cancelled booking does not count:
     * "I used to have a seat" is not a reason to be handed somebody's live position, and a
     * completed one belongs to a journey that has finished.
     */
    return Booking::query()
        ->where('scheduled_trip_id', $session->scheduled_trip_id)
        ->where('passenger_user_id', $user->id)
        ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
        ->exists();
});

/*
 * One day of one commute: the seat race (Feature 03 v5's outbox).
 *
 * 🔒 Per OCCURRENCE, never per commute. A commute-wide channel would carry every morning's seat
 * news to everybody who has ever ridden it, including people with no booking on the day in
 * question — and what travels here is who is taking which seat.
 *
 * 🔒 Admitted on an OPEN interest in this day, which is wider than the trip channel above and
 * deliberately so. That one carries live positions and is closed the moment a journey ends; this
 * one carries "a seat opened" and "somebody took it", which is precisely the news a woman waiting
 * for a seat needs and who does not have a booking at all yet. So a waitlisted or pending seat
 * request counts — otherwise the people the fan-out exists for are the only ones who cannot hear
 * it.
 *
 * What is still refused: anybody with no live interest in this day. A cancelled booking, an expired
 * request, a stranger with an id.
 */
Broadcast::channel('occurrence.{scheduledTripId}', function (User $user, string $scheduledTripId): bool {
    $trip = ScheduledTrip::query()->whereKey($scheduledTripId)->with('commuteOffer')->first();

    if ($trip === null) {
        return false;
    }

    // The driver, whose commute it is.
    if ($trip->commuteOffer?->driver_profile_id === $user->id) {
        return true;
    }

    $holdsASeat = Booking::query()
        ->where('scheduled_trip_id', $trip->getKey())
        ->where('passenger_user_id', $user->id)
        ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
        ->exists();

    if ($holdsASeat) {
        return true;
    }

    /*
     * Or she is waiting for one. A request scoped to this day, or a recurring one on this commute
     * with no single day named — the same two shapes the fan-out itself considers eligible, so the
     * people who receive an offer are exactly the people who can hear it being made.
     */
    return SeatRequest::query()
        ->where('passenger_user_id', $user->id)
        ->where('commute_offer_id', $trip->commute_offer_id)
        ->whereIn('status', [SeatRequestStatus::Waitlisted, SeatRequestStatus::Pending, SeatRequestStatus::Counteroffered])
        ->where(fn ($query) => $query
            ->whereNull('scheduled_trip_id')
            ->orWhere('scheduled_trip_id', $trip->getKey()))
        ->exists();
});

/*
 * Somebody's own channel, for news that is hers alone.
 *
 * 🔒 The simplest callback in the file and the one with the least room for error: she may listen to
 * herself and to nobody else. No lookup, no state, no window — an id that is not hers is refused
 * whatever else is true about her.
 */
Broadcast::channel('user.{userId}', fn (User $user, string $userId): bool => $user->id === $userId);
