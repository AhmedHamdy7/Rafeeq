<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Booking\Support\FeeSplit;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Models\CommuteGroup;

/**
 * Turns an approved seat request into a booking for one day.
 *
 * 🔒 Three money figures are frozen here and never recomputed: the price, the
 * platform's share and the driver's share. Pitfall #42 is computing the fee at
 * collection time instead — the percentage changes from 3% to 4% and bookings
 * made last month are suddenly charged the new rate. Pitfall #13 is the same
 * shape for price: a driver raises the fare and people who already booked are
 * billed the new one.
 *
 * Called only from inside a transaction that holds a lock on the trip. It does
 * not check seat availability itself — the caller does that under the lock, and a
 * check here would either duplicate it or give false comfort.
 */
final readonly class CreateBookingAction
{
    public function execute(SeatRequest $request, ScheduledTrip $trip, ?CommuteGroup $group = null): Booking
    {
        $split = FeeSplit::forSeats($trip->price_snapshot_piastres, $request->seats);

        $booking = new Booking;

        $booking->fill([
            'scheduled_trip_id' => $trip->id,
            'passenger_user_id' => $request->passenger_user_id,
            // Denormalised so a driver's own bookings can be listed without
            // joining through the trip and the offer.
            'driver_profile_id' => $trip->commuteOffer->driver_profile_id,
            'commute_group_id' => $group?->id,
            'seat_request_id' => $request->id,
            'seats_reserved' => $request->seats,
            'price_snapshot_piastres' => $split['price'],
            'platform_fee_snapshot_piastres' => $split['platformFee'],
            'driver_amount_snapshot_piastres' => $split['driverAmount'],
            'payment_type' => $request->payment_type->value,
            'pickup_place_id' => $request->custom_pickup_place_id,
        ]);

        /*
         * The code the passenger checks against the car at the kerb, shown beside the plate on the
         * active-trip screen.
         *
         * 🔴 Minted here, with the booking, rather than at departure — she needs it the evening
         * before, when she is looking at tomorrow's journey and deciding whether to go.
         *
         * 🔒 It decides NOTHING. D18 gives attendance to the driver's tap, and the Master Plan
         * rejected a code for that job outright: "a PIN fails when somebody is running for the
         * car." This answers the other question — "is this the right car?" — and no money, no-show
         * or refusal may ever depend on it.
         */
        $booking->boarding_code = self::newBoardingCode();

        /*
         * Confirmed on creation, not pending.
         *
         * Chapter 6 shows `pending -> confirmed`, and that sequence belongs to
         * the book's simpler model where a passenger books directly. Here a
         * DRIVER has just approved a request they read — the seat is theirs, and
         * leaving it pending would invent a second approval nobody performs.
         */
        $booking->status = BookingStatus::Confirmed->value;

        // Cash is settled in the car, so nothing is owed to us yet; an online
        // payment becomes due now. Neither is collected in Phase 7.
        $booking->payment_status = $request->payment_type === PaymentType::Cash
            ? PaymentStatus::NotDue->value
            : PaymentStatus::Pending->value;

        $booking->save();

        BookingEventLog::record(
            $booking,
            BookingEventType::Confirmed,
            BookingActorType::Driver,
            $request->responded_by,
            from: null,
            metadata: ['seat_request_id' => $request->id],
        );

        return $booking;
    }

    /**
     * Four digits, read aloud through a car window.
     *
     * 🔒 `random_int`, not `rand`: it is cryptographically seeded, and the cost of getting this
     * wrong is that somebody can predict the code a stranger is about to be asked for. Four digits
     * is only 10,000 possibilities, so predictability is the whole of its strength — guessing is
     * already cheap, which is exactly why this may never be the thing that decides anything.
     *
     * Not checked for uniqueness, deliberately. It is scoped to one passenger on one journey and
     * compared by a person who already has the plate in front of her, so two passengers sharing
     * `7391` on different mornings collide with nothing. A uniqueness loop would imply a guarantee
     * the field does not make.
     */
    private static function newBoardingCode(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
