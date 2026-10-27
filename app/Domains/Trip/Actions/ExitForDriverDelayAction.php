<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Support\ReliabilityLedger;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Support\DriverDelay;
use Illuminate\Support\Facades\DB;

/**
 * She gives up on a journey the driver never came for (Feature 03 v5 §241).
 *
 * > *"Passenger can exit without fee if driver hasn't started by departure or missed agreed
 * > pickup."*
 *
 * 🔴 **The point of this endpoint is the three things it does NOT do to her.** She could already
 * cancel at any time before pickup — the button existed. What it cost her was a cancellation fee,
 * a line in the cancellation history a future driver reads, and a mark on her reliability record,
 * for a morning somebody else ruined. All three now land on the driver instead, which is where they
 * belong, and none of it is a judgement call: `DriverDelay` answers from facts the
 * server owns, so the entitlement cannot be claimed by a client that simply asserts it.
 *
 * ⚠️ **The seat is released but NOT offered to the waiting list.** The same reasoning as a
 * displacement: announcing "a seat just opened" for a run that is already late, minutes after
 * departure, reaches people who could not possibly take it — and one of them may be watching the
 * same driver fail to arrive.
 *
 * ⚠️ **And it is not a cancellation.** v5 also wants her able to request an alternative while the
 * old booking is still confirmed and then switch atomically with no fee — that path exists, through
 * `POST /seat-offers/{offer}/accept` with `switchReason: driver_delay`. This is the other half: the
 * woman who has no alternative to switch to, and who today pays to walk away.
 */
final readonly class ExitForDriverDelayAction
{
    public function execute(Booking $booking, ?string $reason = null): Booking
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw DomainException::of(ErrorCode::BookingNotCancellable, fields: [
                'status' => [strtoupper($booking->status->value)],
            ]);
        }

        $trip = ScheduledTrip::query()
            ->whereKey($booking->scheduled_trip_id)
            ->with('tripSession')
            ->firstOrFail();

        /*
         * 🔴 Checked here and not taken from the request. A client that could assert "my driver was
         * late" would have a free cancellation for the asking, and the cost would fall on a driver
         * who was on time.
         */
        $entitlement = DriverDelay::reasonFor($booking, $trip);

        if ($entitlement === null) {
            throw DomainException::of(ErrorCode::BookingNotCancellable, fields: [
                /*
                 * Named so the client can say the true thing: she is not entitled YET. The ordinary
                 * cancel endpoint is still there, and it is the honest place to send her — with
                 * whatever the fee turns out to be, because at this moment nobody has let her down.
                 */
                'driverDelay' => ['NOT_DELAYED'],
                'departureAt' => [$trip->departure_at?->toIso8601String() ?? ''],
            ]);
        }

        return DB::transaction(function () use ($booking, $trip, $reason, $entitlement): Booking {
            // Locked for the same reason as taking a seat: the count must not be read by anybody
            // else between this read and the write.
            $locked = ScheduledTrip::query()->lockForUpdate()->findOrFail($trip->getKey());

            $booking->forceFill([
                'status' => BookingStatus::ExitedDueToDriverDelay->value,
                'cancelled_at' => now(),
                // The server's finding, not her words: `not_started` or `passed_your_stop`.
                'cancelled_reason' => $reason ?? $entitlement,
                // 🔴 Nothing. This is the whole feature.
                'cancellation_fee_piastres' => 0,
            ])->save();

            $release = min($booking->seats_reserved, $locked->seats_taken);

            if ($release > 0) {
                $locked->decrement('seats_taken', $release);
            }

            BookingEventLog::record(
                $booking,
                BookingEventType::Cancelled,
                BookingActorType::Passenger,
                $booking->passenger_user_id,
                from: BookingStatus::Confirmed,
                metadata: ['driver_delay' => $entitlement, 'reason' => $reason, 'fee_waived' => true],
            );

            /*
             * 🔴 On the DRIVER's record, and nothing on hers. A morning somebody else wasted is the
             * exact thing the Reliability Score was built to measure, and this is the one kind of
             * event it records on somebody because a different person pressed a button.
             */
            $this->recordAgainstTheDriver($trip, $booking, $entitlement);

            $this->tellTheDriver($trip, $booking, $entitlement);

            return $booking->refresh();
        });
    }

    private function recordAgainstTheDriver(ScheduledTrip $trip, Booking $booking, string $entitlement): void
    {
        $trip->loadMissing('commuteOffer');

        $driverId = $trip->commuteOffer?->driver_profile_id;

        if ($driverId === null) {
            return;
        }

        ReliabilityLedger::record(
            (string) $driverId,
            ReliabilityEventKind::DriverLateCausedExit,
            booking: $booking,
            scheduledTripId: (string) $trip->getKey(),
            commuteOfferId: (string) $trip->commute_offer_id,
        );
    }

    /**
     * The driver learns she has lost a passenger, and why.
     *
     * She is told because she may still be able to save the rest of the morning — and because a
     * seat silently emptying while she drives is how a run ends with somebody wondering whether to
     * wait at a stop nobody is standing at.
     */
    private function tellTheDriver(ScheduledTrip $trip, Booking $booking, string $entitlement): void
    {
        $trip->loadMissing('commuteOffer.driverProfile.user');

        $driver = $trip->commuteOffer?->driverProfile?->user;

        if ($driver === null) {
            return;
        }

        Notifier::send($driver, NotificationType::PassengerExitedDriverDelay, params: [
            'name' => $booking->passenger?->public_first_name ?? '',
        ], data: [
            'bookingId' => (string) $booking->getKey(),
            'tripId' => (string) $trip->getKey(),
            'reason' => $entitlement,
        ]);
    }
}
