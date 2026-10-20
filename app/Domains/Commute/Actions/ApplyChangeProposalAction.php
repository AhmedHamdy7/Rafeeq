<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\WaitlistPositions;
use App\Domains\Commute\Enums\ChangeProposalStatus;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Support\DepartureTimeCalculator;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use Illuminate\Support\Facades\DB;

/**
 * Putting an agreed change into force (Feature 03 v5 §6).
 *
 * Reached only from {@see RespondToChangeProposalAction} on the answer that makes the proposal
 * unanimous. It is a separate class because applying is a different kind of work from collecting
 * answers — it rewrites schedules, routes and waiting lists — and because the one rule that governs
 * it is worth stating alone: **nothing here runs unless every affected passenger has said yes.**
 */
final readonly class ApplyChangeProposalAction
{
    public function __construct(private BuildNavigationItineraryAction $itinerary) {}

    public function execute(ChangeProposal $proposal): ChangeProposal
    {
        return DB::transaction(function () use ($proposal): ChangeProposal {
            $changes = $proposal->changes;

            if (isset($changes['departureTime']) || isset($changes['promisedArrivalTime'])) {
                $this->applyTimes($proposal, $changes);
            }

            if (isset($changes['pickupPoints'])) {
                $this->applyPickupPoints($proposal, $changes['pickupPoints']);
            }

            /*
             * Every affected day's navigation itinerary is now wrong: a moved departure moves every
             * ETA on it, and moved pickup points move the stops themselves.
             */
            foreach ($proposal->affectedTrips()->pluck('id') as $tripId) {
                $this->itinerary->bump($tripId);
            }

            $proposal->forceFill([
                'status' => ChangeProposalStatus::Applied->value,
                'applied_at' => now(),
            ])->save();

            /*
             * 🔴 v5: an Edit Entire Commute clears the waiting list AFTER a successful application —
             * *"clear the entire old waiting list and notify all members to reapply, even those
             * apparently still compatible. If proposal rejected/unapplied, do not clear."*
             *
             * "Even those apparently still compatible" is the part that matters and the part it
             * would be tempting to optimise away. We cannot tell from a 07:05 request whether 07:30
             * still works for her — her other commitments are not ours to model — so keeping the
             * ones that look fine would mean deciding on her behalf that a different morning suits
             * her. Asking is the honest move, and it is the driver's own change that caused it.
             */
            if ($proposal->scope->rewritesTheTemplate()) {
                $this->clearTheWaitingList($proposal);
            }

            $this->tellEverybody($proposal);

            return $proposal;
        });
    }

    /**
     * The new departure and promised arrival.
     *
     * @param  array<string, mixed>  $changes
     */
    private function applyTimes(ChangeProposal $proposal, array $changes): void
    {
        $trips = $proposal->affectedTrips()->get();

        /*
         * The weekday row first, for a template-wide change: it is the source of truth that
         * generation reads, so a day created next month has to come out with the new time. Updating
         * only the existing trips would leave the change reverting itself the moment the horizon
         * rolled forward.
         */
        if ($proposal->scope->rewritesTheTemplate()) {
            $this->applyToWeekdayRow($proposal, $changes);
        }

        foreach ($trips as $trip) {
            $attributes = [];

            if (isset($changes['departureTime'])) {
                $local = $trip->trip_date->toDateString().' '.$changes['departureTime'];

                $attributes['departure_local'] = $local;
                // Converted through the same calculator generation uses, so a change lands on the
                // same instant a freshly generated day would have.
                $attributes['departure_at'] = DepartureTimeCalculator::toUtc(
                    $trip->trip_date->toDateString(),
                    $changes['departureTime'],
                    $proposal->commuteOffer?->schedule?->timezone ?? config('app.timezone'),
                );
            }

            if (isset($changes['promisedArrivalTime'])) {
                $attributes['promised_arrival_at'] = DepartureTimeCalculator::toUtc(
                    $trip->trip_date->toDateString(),
                    $changes['promisedArrivalTime'],
                    $proposal->commuteOffer?->schedule?->timezone ?? config('app.timezone'),
                );
            }

            $trip->forceFill($attributes)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function applyToWeekdayRow(ChangeProposal $proposal, array $changes): void
    {
        $trip = $proposal->scheduledTrip;

        if ($trip?->departure_local === null) {
            return;
        }

        // Saturday is bit 1 in this codebase's mask; PHP's dayOfWeek has Sunday at 0.
        $bit = 1 << (((int) $trip->departure_local->dayOfWeek + 1) % 7);

        $row = CommuteWeekdaySchedule::query()
            ->where('commute_offer_id', $proposal->commute_offer_id)
            ->where('weekday_bit', $bit)
            ->first();

        if ($row === null) {
            return;
        }

        $attributes = [];

        if (isset($changes['departureTime'])) {
            $attributes['departure_time'] = $changes['departureTime'];
        }

        if (isset($changes['promisedArrivalTime'])) {
            $attributes['promised_arrival_time'] = $changes['promisedArrivalTime'];
        }

        $row->forceFill($attributes)->save();
    }

    /**
     * The commute's advertised pickup points, replaced as a set.
     *
     * Replaced rather than patched for the reason {@see SaveCommuteRouteAction} already gives: a
     * route is one thing, and a partial update leaves sequence numbers to reconcile. The origin and
     * destination are untouched — v5 keeps them as the driver's own endpoints, and a proposal about
     * where she will stop for passengers says nothing about where her journey begins.
     *
     * @param  array<int, array<string, mixed>>  $points
     */
    private function applyPickupPoints(ChangeProposal $proposal, array $points): void
    {
        $offer = $proposal->commuteOffer;

        if ($offer === null) {
            return;
        }

        CommuteLocation::query()
            ->where('commute_offer_id', $offer->getKey())
            ->where('type', CommuteLocationType::Pickup->value)
            ->delete();

        // After the origin (0) and before the destination (999), in the order she listed them.
        $sequence = 1;

        foreach ($points as $point) {
            $location = new CommuteLocation;

            $location->fill([
                'commute_offer_id' => $offer->getKey(),
                'type' => CommuteLocationType::Pickup->value,
                'point' => new Coordinate((float) $point['lat'], (float) $point['lng']),
                'lat' => $point['lat'],
                'lng' => $point['lng'],
                'address' => $point['address'] ?? null,
                'sequence' => $sequence++,
            ]);

            $location->save();
        }
    }

    /**
     * Everybody waiting is asked to apply again on the new terms.
     */
    private function clearTheWaitingList(ChangeProposal $proposal): void
    {
        $waiting = SeatRequest::query()
            ->where('commute_offer_id', $proposal->commute_offer_id)
            ->where('status', SeatRequestStatus::Waitlisted->value)
            ->with('passenger')
            ->get();

        foreach ($waiting as $request) {
            $request->forceFill([
                /*
                 * Its own status, because the alternatives all say something false. `expired` is
                 * what the unanswered-request sweep writes and would read as "she never heard
                 * back"; `rejected` would put a refusal on the driver's record for a queue she
                 * cleared by changing her own times; `withdrawn` would say the passenger left.
                 * What happened is that the commute she queued for is not the commute it is now.
                 */
                'status' => SeatRequestStatus::TermsChanged->value,
                'waitlist_position' => null,
            ])->save();

            if ($request->passenger !== null) {
                Notifier::send($request->passenger, NotificationType::WaitlistClearedByChange, data: [
                    'commuteId' => $proposal->commute_offer_id,
                ]);
            }
        }

        WaitlistPositions::renumber($proposal->commute_offer_id);
    }

    private function tellEverybody(ChangeProposal $proposal): void
    {
        foreach ($proposal->affectedBookings()->with('passenger')->get() as $booking) {
            if ($booking->passenger !== null) {
                Notifier::send($booking->passenger, NotificationType::ChangeProposalApplied, data: [
                    'changeProposalId' => $proposal->getKey(),
                    'bookingId' => $booking->getKey(),
                ]);
            }
        }

        $proposal->loadMissing('commuteOffer.driverProfile.user');

        $driver = $proposal->commuteOffer?->driverProfile?->user;

        if ($driver !== null) {
            Notifier::send($driver, NotificationType::ChangeProposalApplied, data: [
                'changeProposalId' => $proposal->getKey(),
                'commuteId' => $proposal->commute_offer_id,
            ]);
        }
    }
}
