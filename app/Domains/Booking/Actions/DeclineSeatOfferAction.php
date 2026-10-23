<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * She turns down one offered seat (Feature 03 v5: *"Only declines this offer, doesn't remove
 * membership"*).
 *
 * 🔴 **The whole specification is in that second clause**, and it is the thing an implementation
 * gets wrong by being helpful. Dropping her off the waiting list on a decline looks tidy — she said
 * no, so stop bothering her — and it is the opposite of what a waiting list is for. She wants a seat
 * in this car; she cannot take THIS Tuesday. Removing her would make a single inconvenient morning
 * cost her a place she has been waiting weeks for, and she would have to ask the driver to approve
 * her all over again.
 *
 * So this closes exactly one row and touches nothing else: her status stays `waitlisted`, her
 * position is untouched, and the next release offers it to her with everybody else.
 *
 * ⚠️ **No seat is handed on as a result.** A fan-out already offered this release to every eligible
 * member at once, so there is nobody waiting behind her to promote — which is also why a decline
 * notifies nobody. The driver learns who took the seat, not who passed on it: v5 gives her
 * `Seat opened · offered to all` and then `First to accept got it`, and a list of refusals would
 * turn a woman's ordinary Tuesday into something she has to explain.
 */
final readonly class DeclineSeatOfferAction
{
    public function execute(SeatOffer $offer): SeatOffer
    {
        return DB::transaction(function () use ($offer): SeatOffer {
            /*
             * Re-read inside the transaction. Between her tap and this line somebody else may have
             * accepted — and then the honest answer is "somebody was quicker", not "you declined
             * it", because the offer she was looking at is gone either way and only one of those
             * two is true.
             */
            $offer->refresh();

            if (! $offer->status->isLive()) {
                throw DomainException::of(ErrorCode::SeatOfferClosed, fields: [
                    'status' => [$offer->status->value],
                ]);
            }

            /*
             * One row, and deliberately only one. There is no second statement here, and the
             * absence is the feature: her `seat_requests` row — status, waitlist position, the
             * driver's approval of her — is not read and not written. `SeatRequestStatus::Waitlisted`
             * is where she stays, and a test holds it.
             */
            $offer->forceFill([
                'status' => SeatOfferStatus::Declined->value,
                'closed_at' => now(),
            ])->save();

            return $offer;
        });
    }
}
