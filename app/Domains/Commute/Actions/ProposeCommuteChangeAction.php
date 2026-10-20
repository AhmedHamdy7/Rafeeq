<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ChangeProposalScope;
use App\Domains\Commute\Enums\ChangeProposalStatus;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A driver asking her confirmed passengers to agree to a change (Feature 03 v5 §6).
 *
 * > *"Route/time/pickup changes affecting confirmed passengers require unanimous explicit approval,
 * > revisions reset approvals; no response means no change."*
 *
 * 🔴 **This is the one way a published commute's departure or advertised stops can change at all.**
 * `CommuteState::assertEditable()` allows those edits only on a DRAFT, because people book specific
 * days on the strength of both — so until now the answer to "can she move her 7:05?" was simply no.
 * v5 asks for a yes with a condition attached, and the condition is every affected passenger's
 * explicit agreement. The edit does not exist outside this path.
 *
 * 🔴 **Price is deliberately NOT proposable.** v5: *"Price changes only affect new bookings;
 * snapshot agreed seat price in each booking"* — a confirmed seat's price never moves, so there is
 * nothing to ask anybody about, and `ReviseCommuteOfferAction` already changes it for future
 * bookings directly. Putting price in a proposal would imply existing riders could be repriced by
 * agreeing, which v5 forbids outright.
 */
final readonly class ProposeCommuteChangeAction
{
    /**
     * @param  array<string, mixed>  $input  validated upstream: scope, scheduledTripId, and any of
     *                                       departureTime / promisedArrivalTime / pickupPoints
     */
    public function propose(CommuteOffer $offer, array $input): ChangeProposal
    {
        $trip = $this->affectedDay($offer, $input['scheduledTripId']);
        $scope = ChangeProposalScope::from($input['scope']);

        $changes = $this->changesFrom($input);

        if ($changes === []) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'changes' => [__('validation.custom.change_proposal.required')],
            ]);
        }

        $this->assertPickupScope($scope, $changes);

        /*
         * 🔒 One live proposal per commute.
         *
         * Two at once would ask the same passenger to agree to two different mornings with no way
         * to say which won, and whichever reached unanimity first would silently invalidate the
         * other's comparison. v5's editor is one combined request for exactly this reason — the
         * driver revises the proposal she has rather than sending a second.
         */
        $live = ChangeProposal::query()
            ->where('commute_offer_id', $offer->getKey())
            ->whereIn('status', [ChangeProposalStatus::Pending->value, ChangeProposalStatus::Rejected->value])
            ->exists();

        if ($live) {
            throw DomainException::of(ErrorCode::ChangeProposalAlreadyOpen);
        }

        $affected = $this->affectedCount($offer, $trip, $scope, $input);

        if ($affected === 0) {
            /*
             * Nobody to ask. Refused rather than applied, because a proposal is a request for
             * agreement and there is nobody to agree — and because applying a change through this
             * path with no approvals would be a route around the mechanism. A day with no confirmed
             * passengers is hers to edit, and that is a different operation.
             */
            throw DomainException::of(ErrorCode::ChangeProposalNobodyAffected);
        }

        return DB::transaction(function () use ($offer, $trip, $scope, $changes, $input): ChangeProposal {
            $proposal = new ChangeProposal;

            $proposal->forceFill([
                'commute_offer_id' => $offer->getKey(),
                'scheduled_trip_id' => $trip->getKey(),
                'scope' => $scope->value,
                'changes' => $changes,
                // What she is changing FROM, captured now, so the comparison a passenger agrees to
                // cannot drift under her.
                'previous' => $this->currentTerms($offer, $trip, $changes),
                'status' => ChangeProposalStatus::Pending->value,
                'version' => 1,
                'effective_from' => $scope->rewritesTheTemplate()
                    ? ($input['effectiveFrom'] ?? $trip->trip_date->toDateString())
                    : null,
                'expires_at' => $this->expiryFor($trip),
            ])->save();

            $this->tellAffectedPassengers($proposal, NotificationType::ChangeProposalReceived);

            return $proposal;
        });
    }

    /**
     * The driver changing her mind about what she is asking for.
     *
     * 🔴 Every approval stops counting. v5: *"Editing resets all approvals; show warning before
     * revision"* — somebody who agreed to 07:45 has not agreed to 07:30, and carrying her yes
     * across would apply a change she never saw. The old answers are kept, keyed to the version
     * they answered, so the driver's screen can still show that she had agreed before.
     *
     * Allowed from `rejected` as well as `pending`: a refusal is the most likely reason to revise,
     * and v5's driver view offers `Edit Proposal` from there.
     *
     * @param  array<string, mixed>  $input
     */
    public function revise(ChangeProposal $proposal, array $input): ChangeProposal
    {
        if (! $proposal->status->isLive()) {
            throw DomainException::of(ErrorCode::ChangeProposalNotLive, fields: [
                'status' => [$proposal->status->value],
            ]);
        }

        $changes = $this->changesFrom($input);

        if ($changes === []) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'changes' => [__('validation.custom.change_proposal.required')],
            ]);
        }

        $this->assertPickupScope($proposal->scope, $changes);

        return DB::transaction(function () use ($proposal, $changes): ChangeProposal {
            $proposal->forceFill([
                'changes' => $changes,
                // Bumped, which is what resets the approvals: an answer carries the version it
                // answered, and nothing counts towards a version it has not seen.
                'version' => $proposal->version + 1,
                // Back to pending even from rejected: the refusal was of the old version.
                'status' => ChangeProposalStatus::Pending->value,
            ])->save();

            $this->tellAffectedPassengers($proposal, NotificationType::ChangeProposalRevised);

            return $proposal;
        });
    }

    /**
     * The driver taking it back.
     */
    public function withdraw(ChangeProposal $proposal): ChangeProposal
    {
        if (! $proposal->status->isLive()) {
            throw DomainException::of(ErrorCode::ChangeProposalNotLive, fields: [
                'status' => [$proposal->status->value],
            ]);
        }

        $proposal->forceFill([
            'status' => ChangeProposalStatus::Withdrawn->value,
            'withdrawn_at' => now(),
        ])->save();

        return $proposal;
    }

    /**
     * Proposals whose day has gone.
     *
     * v5: *"Proposal expires at the original locked departure time."* Swept rather than judged on
     * read, because an open proposal blocks the driver from sending another one — so leaving it
     * would mean she cannot ask about next Tuesday because last Tuesday's question is still on the
     * table.
     *
     * @return int how many expired
     */
    public static function expireOverdue(): int
    {
        return ChangeProposal::query()
            ->whereIn('status', [ChangeProposalStatus::Pending->value, ChangeProposalStatus::Rejected->value])
            ->where('expires_at', '<=', now())
            ->update(['status' => ChangeProposalStatus::Expired->value]);
    }

    /**
     * Only the fields v5 makes proposable, and only those actually present.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function changesFrom(array $input): array
    {
        $changes = [];

        foreach (['departureTime', 'promisedArrivalTime'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null) {
                $changes[$field] = $input[$field];
            }
        }

        if (array_key_exists('pickupPoints', $input) && is_array($input['pickupPoints'])) {
            $changes['pickupPoints'] = $input['pickupPoints'];
        }

        return $changes;
    }

    /**
     * 🔴 A pickup change cannot be scoped to one day.
     *
     * v5 keeps one advertised route per commute — *"Driver route fixed across recurring weekdays"*,
     * and explicitly not *"different shared advertised routes inside one recurring template"*. The
     * advertised stops live on the commute, not on a day, so moving them for Tuesday alone would
     * either change every other morning as a side effect or require a second route the model does
     * not have. The honest answer is to make her say she means all of them.
     *
     * @param  array<string, mixed>  $changes
     */
    private function assertPickupScope(ChangeProposalScope $scope, array $changes): void
    {
        if (isset($changes['pickupPoints']) && ! $scope->rewritesTheTemplate()) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'scope' => [__('validation.custom.change_proposal.pickup_scope')],
            ]);
        }
    }

    private function affectedDay(CommuteOffer $offer, string $tripId): ScheduledTrip
    {
        return ScheduledTrip::query()
            ->where('commute_offer_id', $offer->getKey())
            ->whereKey($tripId)
            ->where('departure_at', '>', now())
            ->first()
            // A day that has gone, or one on somebody else's commute. 404-shaped either way.
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function affectedCount(
        CommuteOffer $offer,
        ScheduledTrip $trip,
        ChangeProposalScope $scope,
        array $input,
    ): int {
        // Built on a throwaway instance so the count uses exactly the same definition of "affected"
        // the proposal itself will, rather than a second query that could disagree with it.
        $probe = new ChangeProposal;

        $probe->forceFill([
            'commute_offer_id' => $offer->getKey(),
            'scheduled_trip_id' => $trip->getKey(),
            'scope' => $scope->value,
            'effective_from' => $input['effectiveFrom'] ?? $trip->trip_date->toDateString(),
        ]);

        $probe->setRelation('scheduledTrip', $trip);

        return $probe->affectedBookings()->count();
    }

    /**
     * What the proposed fields are set to today.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function currentTerms(CommuteOffer $offer, ScheduledTrip $trip, array $changes): array
    {
        $previous = [];

        if (isset($changes['departureTime'])) {
            $previous['departureTime'] = $trip->departure_local?->format('H:i:s');
        }

        if (isset($changes['promisedArrivalTime'])) {
            $previous['promisedArrivalTime'] = $this->weekdayRow($offer, $trip)?->promised_arrival_time;
        }

        if (isset($changes['pickupPoints'])) {
            $offer->loadMissing('locations');

            $points = [];

            foreach ($offer->locations->where('type', CommuteLocationType::Pickup)->sortBy('sequence') as $pickup) {
                $points[] = [
                    'lat' => (float) $pickup->lat,
                    'lng' => (float) $pickup->lng,
                    'address' => $pickup->address,
                ];
            }

            $previous['pickupPoints'] = $points;
        }

        return $previous;
    }

    private function weekdayRow(CommuteOffer $offer, ScheduledTrip $trip): ?CommuteWeekdaySchedule
    {
        return CommuteWeekdaySchedule::query()
            ->where('commute_offer_id', $offer->getKey())
            ->where('weekday_bit', $trip->departure_local === null
                ? 0
                : (1 << (((int) $trip->departure_local->dayOfWeek + 1) % 7)))
            ->first();
    }

    /**
     * The moment it stops being answerable: the affected day's departure.
     *
     * For a future scope that is still the day she was looking at, because v5 ties expiry to "the
     * original locked departure time" — the morning the question was about. A proposal that
     * outlived it would let a late yes rewrite a journey that already happened.
     */
    private function expiryFor(ScheduledTrip $trip): CarbonImmutable
    {
        return CarbonImmutable::parse($trip->departure_at);
    }

    private function tellAffectedPassengers(ChangeProposal $proposal, NotificationType $type): void
    {
        $bookings = $proposal->affectedBookings()->with('passenger')->get();

        foreach ($bookings as $booking) {
            if ($booking->passenger === null) {
                continue;
            }

            Notifier::send($booking->passenger, $type, data: [
                'changeProposalId' => $proposal->getKey(),
                'bookingId' => $booking->getKey(),
                'commuteId' => $proposal->commute_offer_id,
            ]);
        }
    }
}
