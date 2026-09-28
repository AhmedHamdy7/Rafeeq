<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
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
