<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Booking\Actions\DisplaceConfirmedBookingsAction;
use App\Domains\Booking\Actions\OfferSeatsToWaitlistMembersAction;
use App\Domains\Booking\Enums\DisplacementReason;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
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
 * Archiving — `DELETE /commutes/{commute}` — is the end, and it now cancels EVERY upcoming day,
 * booked ones included (Feature 03 C-8, COM-35, decision C6). It used to cancel only the days
 * nobody had taken, so passengers kept confirmed seats on a commute that no longer existed and
 * waited at the kerb for a driver who was not coming, and nobody told them.
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
        $vehicle = CreateCommuteOfferAction::usableVehicle($offer->driverProfile, $offer->vehicle_id);
        // C-3: the car's approved seats may have been lowered while the commute was paused.
        CreateCommuteOfferAction::assertCommuteFitsVehicle($offer, $vehicle);

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

            /*
             * 1. Every confirmed upcoming seat, through the one displacement path pause and vehicle
             *    replacement already use — the same cancellation, reliability record and one
             *    notice per passenger. Not put back on the waiting list: there is no commute left.
             */
            $this->displace->forCommute($offer, DisplacementReason::CommuteDeleted);

            // 2. Then every upcoming day, which now has nobody on it.
            $days = $this->cancelFutureTrips($offer, $reason);

            // 3. And every open question about the commute: requests, waiting-list places, offers.
            $this->closeOpenRequests($offer, $days);

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
     * Every day that has not started. A run already under way is left alone — the driver ends it
     * with Cancel Active Trip, and the people in the car are not displaced by a setting.
     *
     * @return list<string> the ids of the days cancelled
     */
    private function cancelFutureTrips(CommuteOffer $offer, ?string $reason): array
    {
        $ids = ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($ids !== []) {
            ScheduledTrip::query()->whereIn('id', $ids)->update([
                'status' => ScheduledTripStatus::Cancelled->value,
                'cancelled_reason' => $reason ?? 'commute_archived',
            ]);
        }

        return $ids;
    }

    /**
     * Requests still waiting on this commute — pending, countered or queued — close as
     * `commute_archived`, and any seat offer still open on its days is closed.
     *
     * 🔴 Needed because requests no longer expire (R-4): before, an open request on a deleted
     * commute quietly timed out after 48 hours. Now nothing else would ever close it. An approved
     * request is closed too: the membership it stood for has no days left.
     *
     * @param  list<string>  $tripIds
     */
    private function closeOpenRequests(CommuteOffer $offer, array $tripIds): void
    {
        SeatRequest::query()
            ->where('commute_offer_id', $offer->id)
            ->whereIn('status', [
                SeatRequestStatus::Pending->value,
                SeatRequestStatus::Waitlisted->value,
                SeatRequestStatus::Counteroffered->value,
                SeatRequestStatus::Approved->value,
            ])
            ->update([
                'status' => SeatRequestStatus::CommuteArchived->value,
                'waitlist_position' => null,
            ]);

        if ($tripIds !== []) {
            SeatOffer::query()
                ->whereIn('scheduled_trip_id', $tripIds)
                ->open()
                ->update([
                    'status' => SeatOfferStatus::Closed->value,
                    'closed_at' => now(),
                ]);
        }
    }
}
