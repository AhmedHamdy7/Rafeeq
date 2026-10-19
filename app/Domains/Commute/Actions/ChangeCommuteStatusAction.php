<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Booking\Actions\DisplaceConfirmedBookingsAction;
use App\Domains\Booking\Actions\OfferSeatsToWaitlistMembersAction;
use App\Domains\Booking\Enums\DisplacementReason;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Payment\Support\DriverDebt;
use Illuminate\Support\Facades\DB;

/**
 * Pausing, resuming and archiving (Chapter 4's state machine and edge cases).
 *
 * 🔴 **What pausing means changed, and this is the important note in the file.**
 *
 * It used to leave existing bookings alone. Chapter 4 says "paused commutes keep history", which
 * was read as "does not cancel days people have booked" — a driver who paused for a week and
 * resumed found her group intact. Feature 03 v5 (2026-10-18) overrides that in terms: *"Driver
 * Pause Commute indefinitely; no mandatory end date. It cancels ALL future confirmed bookings, not
 * active trip; displaced passengers are notified and automatically added to preserved waiting list.
 * Templates and waitlist persist. Canceled seats never auto-restore."*
 *
 * The old reading was the kinder one and the new one is the honest one. A paused commute is not
 * running: the days stop being generated and the commute stops being findable. A confirmed seat on
 * a journey that is not running is a woman planning her morning around a lift that will not come,
 * and she finds out when it does not arrive. Cancelling now tells her while she can still make
 * other arrangements. What "keeps history" survives on: her approval, her terms, her place on the
 * list, the commute's template and its completed trips — none of which pausing touches.
 *
 * Resuming therefore does NOT give the seats back. v5 makes that an explicit exception to the
 * automatic fan-out a freed seat normally gets: the driver chooses who to offer seats to. See
 * {@see OfferSeatsToWaitlistMembersAction}.
 *
 * Archiving is the end. Chapter 4: "archived commutes cannot be booked" — so
 * future days that nobody has taken are cancelled, because leaving them bookable
 * would let someone join a commute that no longer exists.
 */
final readonly class ChangeCommuteStatusAction
{
    public function __construct(
        private GenerateScheduledTripsAction $generateTrips,
        private DisplaceConfirmedBookingsAction $displace,
    ) {}

    public function pause(CommuteOffer $offer, CommutePausedReason $reason): CommuteOffer
    {
        CommuteState::assertCanTransitionTo($offer, CommuteOfferStatus::Paused);

        return DB::transaction(function () use ($offer, $reason): CommuteOffer {
            $offer->forceFill([
                'status' => CommuteOfferStatus::Paused->value,
                'paused_at' => now(),
                'paused_reason' => $reason->value,
            ])->save();

            /*
             * After the status is written, inside the same transaction.
             *
             * The order matters: a passenger whose seat is cancelled is told so, and the commute
             * must already be paused when that happens — otherwise she could open the app from the
             * notification, find the commute still published, and request the seat she was just
             * told she had lost. One transaction means neither half can land without the other.
             */
            $this->displace->forCommute($offer, DisplacementReason::CommutePaused);

            return $offer;
        });
    }

    public function resume(CommuteOffer $offer): CommuteOffer
    {
        CommuteState::assertCanTransitionTo($offer, CommuteOfferStatus::Published);

        // Re-checked on the way back: a commute paused because the vehicle was
        // suspended must not resume just because the driver asked.
        CreateCommuteOfferAction::assertDriverMayPublish($offer->driverProfile);
        DriverDebt::assertMayPublish($offer->driverProfile);
        CreateCommuteOfferAction::usableVehicle($offer->driverProfile, $offer->vehicle_id);

        return DB::transaction(function () use ($offer): CommuteOffer {
            $offer->forceFill([
                'status' => CommuteOfferStatus::Published->value,
                'paused_at' => null,
                'paused_reason' => null,
            ])->save();

            // Catch up on the days that went by while it was paused.
            $this->generateTrips->execute($offer->refresh());

            return $offer;
        });
    }

    public function archive(CommuteOffer $offer, ?string $reason = null): CommuteOffer
    {
        CommuteState::assertCanTransitionTo($offer, CommuteOfferStatus::Archived);

        return DB::transaction(function () use ($offer, $reason): CommuteOffer {
            $offer->forceFill([
                'status' => CommuteOfferStatus::Archived->value,
                'archived_at' => now(),
            ])->save();

            $this->cancelUntakenFutureTrips($offer, $reason);

            return $offer;
        });
    }

    /**
     * Chapter 4's edge cases: a vehicle suspended after publishing pauses the
     * commute automatically, and so does a lapsed licence.
     *
     * Done as a sweep over the driver's published offers rather than as a hook
     * inside the suspension Action, because the same condition can arise without
     * anybody suspending anything — a licence simply expires one morning.
     *
     * @return int how many were paused
     */
    public function pauseUnpublishableOffers(DriverProfile $profile, CommutePausedReason $reason): int
    {
        $paused = 0;

        $offers = CommuteOffer::query()
            ->where('driver_profile_id', $profile->user_id)
            ->where('status', CommuteOfferStatus::Published->value)
            ->get();

        foreach ($offers as $offer) {
            $this->pause($offer, $reason);
            $paused++;
        }

        return $paused;
    }

    /**
     * Only days nobody has taken. A trip with passengers on it is a commitment
     * that has to be cancelled deliberately, with the refunds and notifications
     * that belong to later phases — silently voiding it here would leave people
     * expecting a lift that will not come.
     */
    private function cancelUntakenFutureTrips(CommuteOffer $offer, ?string $reason): void
    {
        ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->where('seats_taken', 0)
            ->update([
                'status' => ScheduledTripStatus::Cancelled->value,
                'cancelled_reason' => $reason ?? 'commute_archived',
            ]);
    }
}
