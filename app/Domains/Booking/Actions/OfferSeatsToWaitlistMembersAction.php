<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\WaitlistEligibility;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The driver picks who on her waiting list gets offered a seat (Feature 03 v5).
 *
 * 🔴 **This is the one place where the driver chooses, and v5 says so as an exception:**
 *
 * > *"Driver manually Resume. **Exception to normal automatic seat-open fanout:** driver chooses
 * > individual, selected subset, or all waiting-list members to offer seats to; may prioritize
 * > previous/recurring passengers. First eligible server-committed acceptance wins. Offers have no
 * > fixed timer while seat/eligibility persists. Driver may withdraw only unaccepted offers."*
 *
 * Everywhere else, a seat that frees goes out to everybody waiting automatically
 * ({@see OfferSeatToWaitlistAction}) — one person drops out, the queue finds out, done. Resume is
 * different in kind: the driver is not filling one hole, she is rebuilding a car full of people
 * from scratch after a pause cancelled all of them. Fanning every seat on every future day out to
 * the whole list at once would hand her car back to whoever is quickest on their phone, for weeks
 * of mornings at a time, and v5 explicitly lets her favour the passengers she had before.
 *
 * What her choice does NOT do is give anybody a seat. It creates offers; the race is still resolved
 * by server transaction order under the trip's row lock, exactly as for an automatic fan-out. So she
 * chooses WHO may race, and the server still decides who wins.
 *
 * ⚠️ Deliberately NOT called by `resume()`. v5: *"Canceled seats never auto-restore"* — resuming
 * makes the commute findable and starts generating days again, and offering seats is a separate act
 * she performs when she is ready. Binding the two would make resume an automatic fan-out, which is
 * the exact thing this exception exists to prevent.
 */
final readonly class OfferSeatsToWaitlistMembersAction
{
    /**
     * @param  Collection<int, SeatRequest>  $members  the waiting people she
     *                                                 chose, already scoped to
     *                                                 this commute by the caller
     * @return int how many offers were created
     */
    public function execute(CommuteOffer $offer, $members): int
    {
        /*
         * A paused commute generates no days and cannot be found, so an offer on one is an
         * invitation to a journey that is not running. Checked here rather than left to the empty
         * result it would produce anyway, so the driver is told why nothing happened.
         */
        if (! $offer->isPublished()) {
            throw DomainException::of(ErrorCode::CommuteNotEditable, fields: [
                'status' => [$offer->status->value],
            ]);
        }

        return DB::transaction(function () use ($offer, $members): int {
            $created = 0;

            /*
             * One id for the whole batch, so "she offered seats to these four people on Tuesday
             * evening" is one event in the data — which is what makes it legible later and makes
             * withdrawing the batch a single statement.
             */
            $releaseEventId = (string) Str::ulid();

            foreach ($members as $member) {
                // Re-read under the transaction: she may have withdrawn, or won a seat elsewhere,
                // between the driver opening the screen and tapping Offer.
                $member->refresh();

                if ($member->status !== SeatRequestStatus::Waitlisted) {
                    continue;
                }

                $days = WaitlistEligibility::tripsForRequest($member)->get();
                $offeredDays = 0;

                foreach ($days as $trip) {
                    /*
                     * `insertOrIgnore` against the unique (trip, request) index, for the reasons
                     * {@see OfferSeatToWaitlistAction} sets out: it leans on the index rather than
                     * racing it, so a double tap cannot give one person two chances at one seat,
                     * and it does not mass-assign a `status` this model refuses on purpose.
                     */
                    $inserted = SeatOffer::query()->insertOrIgnore([
                        'id' => (string) Str::ulid(),
                        'scheduled_trip_id' => $trip->getKey(),
                        'seat_request_id' => $member->getKey(),
                        'status' => SeatOfferStatus::Open->value,
                        'release_event_id' => $releaseEventId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $created += $inserted;
                    $offeredDays += $inserted;
                }

                /*
                 * One notification for the whole set of days, not one per day.
                 *
                 * A recurring member resumed mid-month is eligible for every remaining weekday, and
                 * twenty buzzes for one decision is how notifications get turned off. The count is
                 * in the body so she knows the scale, and the deep link takes her to the offers
                 * themselves.
                 */
                if ($offeredDays > 0 && $member->passenger !== null) {
                    Notifier::send($member->passenger, NotificationType::SeatsOfferedByDriver, params: [
                        'days' => $offeredDays,
                    ], data: [
                        'commuteId' => $offer->getKey(),
                        'seatRequestId' => $member->getKey(),
                    ]);
                }
            }

            return $created;
        });
    }

    /**
     * She takes one offer back.
     *
     * v5 allows this for unaccepted offers only, and the restriction is the whole point: once
     * somebody has accepted, the seat is a booking, and taking it back is a driver cancellation
     * with the fees and reliability impact that carries — not an undo. So anything that is not
     * still open is refused here and has to go through cancellation instead.
     */
    public function withdraw(SeatOffer $seatOffer): SeatOffer
    {
        return DB::transaction(function () use ($seatOffer): SeatOffer {
            // Re-read under the transaction: she may have accepted it a moment ago.
            $seatOffer->refresh();

            if (! $seatOffer->status->isLive()) {
                throw DomainException::of(ErrorCode::SeatOfferClosed, fields: [
                    'status' => [$seatOffer->status->value],
                ]);
            }

            $seatOffer->forceFill([
                'status' => SeatOfferStatus::Withdrawn->value,
                'closed_at' => now(),
            ])->save();

            return $seatOffer;
        });
    }
}
