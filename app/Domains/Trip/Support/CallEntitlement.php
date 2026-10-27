<?php

namespace App\Domains\Trip\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Models\TripSession;

/**
 * Who may be handed whose phone number, and when (Feature 03 v5 §247 and §426).
 *
 * > *"Phone opens dialer; driver call icon next passenger only after start; passenger call after
 * > accepted booking."* And, in more detail: *"Driver call icon appears **only for next passenger
 * > to pick up and after trip starts**; passenger call icon **from booking acceptance**. Call opens
 * > device dialer."*
 *
 * 🔒 **The asymmetry is the design, and it is not an accident of wording.** A passenger may call her
 * driver from the moment her seat is confirmed, because she is about to get into that person's car
 * and the ability to reach her is most of what makes that safe. A driver may call only the ONE
 * passenger she is on her way to, and only once the car is moving — because the thing she needs a
 * phone for is "I am at the gate, where are you?", and that need exists for exactly one person at a
 * time and only while she is driving.
 *
 * Read the other way round, the rule says what it refuses: a driver holding five confirmed bookings
 * does not get five phone numbers for the week. Somebody's number is handed over at the moment it is
 * needed and never as a list.
 *
 * 🔴 **This does not conflict with the platform's rule against exposing phone numbers**, and the
 * resolution is in the wording of both. The Engineering Bible forbids `phone_e164` in any **public**
 * API response; v5 itself says to avoid leaking a phone to **unrelated** users. Neither forbids a
 * gated disclosure between two people who are about to share a car — and v5 then specifies exactly
 * which related person, at exactly which moment. So the number is reachable through one explicit
 * call and never as a field on a payload: a booking is fetched in lists, cached by clients and
 * logged by proxies, and a phone number riding along in it would be disclosed to all of that
 * instead of to the person who asked.
 *
 * ⚠️ **The number returned is the real one.** v5 says the call "opens device dialer", so what the
 * client needs is dialable. Masking it behind a relay would be better and is not possible here: it
 * needs a telephony provider, which v5 lists among the integrations it defers. Until then the
 * disclosure is narrowed by the gates above and written to the security log, which is the honest
 * version of "not yet masked".
 */
final class CallEntitlement
{
    /**
     * May this caller be given the other party's number on this booking?
     */
    public static function allows(Booking $booking, string $callerId): bool
    {
        if ($callerId === $booking->passenger_user_id) {
            return self::passengerMayCallDriver($booking);
        }

        if ($callerId === $booking->driver_profile_id) {
            return self::driverMayCallPassenger($booking);
        }

        return false;
    }

    /**
     * From booking acceptance, and for as long as the seat is live.
     *
     * `Completed` counts: v5 closes chat a couple of hours after the run and there is no reason the
     * phone should outlive it by less — the case it exists for is "you left your bag in my car",
     * which only comes up afterwards. A cancelled seat does not: the two of them are not travelling
     * together, and a number handed over on a booking that ended would outlast the reason for it.
     */
    public static function passengerMayCallDriver(Booking $booking): bool
    {
        return in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Completed], true);
    }

    /**
     * Only the passenger she is on her way to, and only once she has set off.
     *
     * 🔴 "Next to pick up" is read off her own itinerary: the earliest pickup stop she has not
     * reached. That is a fact about where the car has got to rather than a guess from a clock — the
     * same `reached_at` the navigation screen is drawn from — so the number becomes available at the
     * moment she is actually approaching somebody and stops the moment she has collected her.
     *
     * ⚠️ With no itinerary built there is no "next", and the answer is no. That is the safe
     * direction: a driver who has not opened her route has not started approaching anybody.
     */
    public static function driverMayCallPassenger(Booking $booking): bool
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            return false;
        }

        $session = TripSession::query()
            ->where('scheduled_trip_id', $booking->scheduled_trip_id)
            ->first();

        // v5: "after trip starts". Not before, however late she is.
        if ($session === null || ! $session->current_status->isUnderway()) {
            return false;
        }

        $nextPickup = NavigationStop::query()
            ->where('scheduled_trip_id', $booking->scheduled_trip_id)
            ->where('kind', NavigationStopKind::PassengerPickup->value)
            ->remaining()
            ->first();

        return $nextPickup !== null && $nextPickup->booking_id === $booking->getKey();
    }

    /**
     * Why the number is not available, for a client that wants to explain rather than grey a button
     * out. Null when it is.
     */
    public static function refusalFor(Booking $booking, string $callerId): ?string
    {
        if (self::allows($booking, $callerId)) {
            return null;
        }

        if ($callerId === $booking->passenger_user_id) {
            return 'booking_not_live';
        }

        $session = TripSession::query()
            ->where('scheduled_trip_id', $booking->scheduled_trip_id)
            ->first();

        return match (true) {
            $booking->status !== BookingStatus::Confirmed => 'booking_not_live',
            $session === null || ! $session->current_status->isUnderway() => 'trip_not_started',
            default => 'not_next_pickup',
        };
    }
}
