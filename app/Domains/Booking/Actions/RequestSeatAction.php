<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\SeatAvailabilityChecker;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Support\HardFilters;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * A passenger asking for a seat.
 *
 * Rafeeq does not book directly: the driver decides who rides with them. A request
 * is therefore a question, and the seat is not held while it is unanswered —
 * holding it would let one unanswered request block a seat somebody else would
 * have taken.
 *
 * Two things are enforced here that the product depends on.
 *
 * **Agreeing to the group's rules is mandatory.** `agreed_to_rules_at` is NOT
 * NULL in the schema and is checked here: a passenger who did not accept the
 * rules has not agreed to travel on the terms the group runs on, and a driver
 * approving them would be agreeing on their behalf.
 *
 * **Eligibility is re-checked with the same filters as the search.** Reusing
 * `HardFilters` rather than writing a second, simpler check is deliberate: a
 * man must not be able to request a seat on a women-only commute by calling this
 * endpoint directly with an id he obtained some other way. One implementation of
 * "may this person ride with this driver" means the two cannot drift apart.
 */
final readonly class RequestSeatAction
{
    public function __construct(private SeatAvailabilityChecker $availability) {}

    /**
     * @param  array<string, mixed>  $input  validated upstream
     */
    public function execute(User $passenger, CommuteOffer $offer, array $input): SeatRequest
    {
        if ($offer->driver_profile_id === $passenger->id) {
            throw DomainException::of(ErrorCode::CannotBookOwnCommute);
        }

        if (empty($input['agreedToRules'])) {
            throw DomainException::of(ErrorCode::RulesNotAgreed);
        }

        $commitment = SeatRequestCommitment::from($input['commitment']);
        $trip = $this->tripFor($offer, $input, $commitment);

        $this->assertPassengerMayRide($passenger, $offer);
        $this->assertNoOpenRequest($passenger, $offer);
        $this->assertDaysAreOffered($offer, $input);

        if ($trip !== null) {
            $this->availability->assertTripIsBookable($trip);
            $this->availability->assertNoDuplicateBooking($trip, $passenger->id);
        }

        return DB::transaction(function () use ($passenger, $offer, $trip, $commitment, $input): SeatRequest {
            $request = new SeatRequest;

            $request->fill([
                'passenger_user_id' => $passenger->id,
                'commute_offer_id' => $offer->id,
                'scheduled_trip_id' => $trip?->id,
                'commitment' => $commitment->value,
                'requested_days_mask' => $input['requestedDaysMask'] ?? null,
                'seats' => $input['seats'] ?? 1,
                'meeting_preference' => $input['meetingPreference'],
                'custom_pickup_place_id' => $input['customPickupPlaceId'] ?? null,
                'intro_message' => $input['introMessage'] ?? null,
                // Recorded as a moment, not a boolean: what matters in a dispute
                // is that they agreed and when, against which version of the rules.
                'agreed_to_rules_at' => now(),
                'payment_type' => $input['paymentType'],
            ]);

            // Not fillable: a request must not be able to declare itself approved,
            // nor claim a place in the queue.
            $waitlisted = $trip !== null && $this->isFull($trip, (int) ($input['seats'] ?? 1));

            $request->status = $waitlisted
                ? SeatRequestStatus::Waitlisted->value
                : SeatRequestStatus::Pending->value;

            if ($waitlisted) {
                $request->waitlist_position = $this->nextWaitlistPosition($offer);
            }

            /*
             * A request nobody answers expires. Leaving it open forever is worse
             * than a refusal: it holds the passenger's one active request for this
             * commute, so they cannot ask again while they wait for nothing.
             */
            $request->expires_at = now()->addHours((int) config('rafeeq.booking.request_expiry_hours'));

            $request->save();

            // Only a request that asks the driver for something. A day that was full put it
            // straight on the waitlist, and there is nothing for her to answer yet.
            if (! $waitlisted) {
                Notifier::send($offer->driverProfile->user, NotificationType::SeatRequested,
                    ['name' => $passenger->public_first_name],
                    ['seatRequestId' => $request->id],
                );
            }

            return $request;
        });
    }

    /**
     * A trial request names a specific day; a recurring one asks about the pattern
     * and is seated day by day once accepted.
     */
    private function tripFor(CommuteOffer $offer, array $input, SeatRequestCommitment $commitment): ?ScheduledTrip
    {
        if (! isset($input['scheduledTripId'])) {
            if ($commitment === SeatRequestCommitment::Trial) {
                // A trial with no day is not a request anybody can answer.
                throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                    'scheduledTripId' => [__('validation.required', ['attribute' => 'scheduledTripId'])],
                ]);
            }

            return null;
        }

        return ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->whereKey($input['scheduledTripId'])
            ->first()
            // 404-shaped: a trip id that belongs to another commute must not be
            // distinguishable from one that does not exist.
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    /**
     * 🔴 The same eligibility rules the search applies — audience, blocks in both
     * directions, trust level, driver still approved — asked of this one commute.
     *
     * Shared with the search rather than reimplemented, because this endpoint takes
     * an id that may have arrived any way at all. Without it, a man could request a
     * seat on a women-only commute simply by calling this directly.
     */
    private function assertPassengerMayRide(User $passenger, CommuteOffer $offer): void
    {
        $allowed = HardFilters::applyEligibility(
            CommuteOffer::query()->whereKey($offer->id),
            $passenger,
        )->exists();

        if (! $allowed) {
            // 404, not 403: telling someone they are excluded from a women-only
            // commute confirms both that it exists and what it is.
            throw DomainException::of(ErrorCode::NotFound);
        }
    }

    /**
     * One open request per passenger per commute. A generated column plus a unique
     * index is what makes it true under a race; this gives an explanation.
     */
    private function assertNoOpenRequest(User $passenger, CommuteOffer $offer): void
    {
        $exists = SeatRequest::query()
            ->where('passenger_user_id', $passenger->id)
            ->where('commute_offer_id', $offer->id)
            ->whereIn('status', [
                SeatRequestStatus::Pending->value,
                SeatRequestStatus::Approved->value,
                SeatRequestStatus::Waitlisted->value,
            ])
            ->exists();

        if ($exists) {
            throw DomainException::of(ErrorCode::AlreadyRequested);
        }
    }

    /**
     * A recurring request may commit to fewer days than the commute runs — three
     * days a week out of five is a normal commitment. What it may not do is commit
     * to a day the commute does not run at all: that day can never be seated, so
     * accepting it would sell a promise nobody can keep.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertDaysAreOffered(CommuteOffer $offer, array $input): void
    {
        $requested = $input['requestedDaysMask'] ?? null;

        if ($requested === null) {
            return;
        }

        $offered = $offer->schedule?->days_mask;

        // Bitwise: every day asked for, with the offered days removed. Anything
        // left is a day this commute does not run.
        if ($offered === null || ((int) $requested & ~$offered) !== 0) {
            throw DomainException::of(ErrorCode::RecurringDaysNotOffered, fields: [
                'requestedDaysMask' => [(string) ($offered ?? 0)],
            ]);
        }
    }

    private function isFull(ScheduledTrip $trip, int $seats): bool
    {
        return $trip->seats_taken + $seats > $trip->seats_total;
    }

    /**
     * The back of the queue.
     *
     * Taken from the highest position in use, NOT from how many people are
     * waiting: with three waiting at 1, 2, 3, the moment number 2 withdraws the
     * count says 2 and the next arrival would be handed position 3 — the same
     * place somebody else is already standing in. The count still decides whether
     * the queue is full, because that is a question about how many people are
     * actually waiting.
     */
    private function nextWaitlistPosition(CommuteOffer $offer): int
    {
        $waiting = SeatRequest::query()
            ->where('commute_offer_id', $offer->id)
            ->where('status', SeatRequestStatus::Waitlisted->value);

        if ($waiting->clone()->count() >= (int) config('rafeeq.booking.max_waitlist_size')) {
            // A queue this long is a false hope, and offering one is worse than
            // saying no.
            throw DomainException::of(ErrorCode::WaitlistFull);
        }

        return (int) $waiting->max('waitlist_position') + 1;
    }
}
