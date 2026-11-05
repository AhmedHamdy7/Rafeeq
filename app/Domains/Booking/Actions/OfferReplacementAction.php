<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\ReplacementRule;
use App\Domains\Booking\Support\WaitlistEligibility;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class OfferReplacementAction
{
    public function execute(Booking $booking, string $seatRequestId): SeatOffer
    {
        return DB::transaction(function () use ($booking, $seatRequestId): SeatOffer {
            $trip = ScheduledTrip::query()->lockForUpdate()->findOrFail($booking->scheduled_trip_id);

            $booking->refresh();

            if ($booking->status !== BookingStatus::Confirmed
                || $trip->status !== ScheduledTripStatus::Scheduled
                || $trip->departure_at->isPast()) {
                throw DomainException::of(ErrorCode::BookingNotCancellable, fields: [
                    'status' => [strtoupper($booking->status->value)],
                ]);
            }

            $member = SeatRequest::query()
                ->whereKey($seatRequestId)
                ->where('commute_offer_id', $trip->commute_offer_id)
                ->where('status', SeatRequestStatus::Waitlisted->value)
                ->with('passenger')
                ->first()
                ?? throw DomainException::of(ErrorCode::NotFound);

            if (SeatOffer::query()->where('replaces_booking_id', $booking->getKey())->open()->exists()) {
                throw DomainException::of(ErrorCode::ReplacementAlreadyOpen);
            }

            $this->assertEligible($booking, $member, $trip);

            ReplacementRule::assertChangesNobody($booking, $member, $trip);

            $offer = new SeatOffer;

            $offer->forceFill([
                'id' => (string) Str::ulid(),
                'scheduled_trip_id' => $trip->getKey(),
                'seat_request_id' => $member->getKey(),
                'replaces_booking_id' => $booking->getKey(),
                'status' => SeatOfferStatus::Open->value,
                'release_event_id' => (string) Str::ulid(),
            ])->save();

            if ($member->passenger !== null) {
                Notifier::send($member->passenger, NotificationType::SeatsOfferedByDriver, params: ['days' => 1], data: [
                    'commuteId' => $trip->commute_offer_id,
                    'seatRequestId' => $member->getKey(),
                ]);
            }

            return $offer->load('seatRequest.passenger', 'scheduledTrip');
        });
    }

    private function assertEligible(Booking $booking, SeatRequest $member, ScheduledTrip $trip): void
    {
        $reason = match (true) {
            $member->passenger_user_id === $booking->passenger_user_id => 'same_passenger',
            ! WaitlistEligibility::isWaitingFor($member, $trip) => 'not_waiting_for_this_day',
            $member->seats > $booking->seats_reserved + max(0, $trip->seats_total - $trip->seats_taken) => 'needs_more_seats',
            SeatOffer::hasLiveOfferFor((string) $trip->getKey(), (string) $member->getKey()) => 'already_offered',
            default => null,
        };

        if ($reason !== null) {
            throw DomainException::of(ErrorCode::ReplacementNotEligible, fields: ['reason' => [$reason]]);
        }
    }
}
