<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\MeetingPreference;
use App\Domains\Booking\Enums\SeatOfferClosedReason;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestKind;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\RequestedPoints;
use App\Domains\Booking\Support\SeatOfferClosure;
use App\Domains\Booking\Support\WaitlistPositions;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

final readonly class UpdateSeatRequestPointsAction
{
    public function __construct(private GeoQueryEngine $geo) {}

    public function execute(SeatRequest $request, array $input): SeatRequest
    {
        if (! self::isEditable($request->status) || $request->requested_during_ride) {
            throw DomainException::of(ErrorCode::SeatRequestNotPending);
        }

        $offer = $request->commuteOffer()->with(['locations', 'schedule', 'driverProfile.user'])->firstOrFail();

        $points = array_key_exists('pickup', $input) ? RequestedPoints::fromInput($this->geo, $offer, $input) : null;
        $days = array_key_exists('requestedDaysMask', $input) ? (int) $input['requestedDaysMask'] : null;

        if ($days !== null) {
            $this->assertDaysFit($request, $offer, $days);
        }

        $changed = DB::transaction(function () use ($request, $input, $points, $days): array {
            $locked = SeatRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! self::isEditable($locked->status)) {
                throw DomainException::of(ErrorCode::SeatRequestNotPending);
            }

            $wasWaiting = $locked->status === SeatRequestStatus::Waitlisted;

            $locked->forceFill([
                ...($points === null ? [] : [
                    ...$points->columns(),
                    'meeting_preference' => MeetingPreference::forPickup($input['pickup'])->value,
                ]),
                ...($days === null ? [] : ['requested_days_mask' => $days]),
                ...(array_key_exists('introMessage', $input) ? ['intro_message' => $input['introMessage']] : []),
                ...($wasWaiting ? [
                    'status' => SeatRequestStatus::Pending->value,
                    'kind' => SeatRequestKind::WaitlistApplication->value,
                    'waitlist_position' => null,
                    'responded_by' => null,
                    'responded_at' => null,
                    'response_note' => null,
                    'expires_at' => null,
                ] : []),
            ])->save();

            if ($wasWaiting) {
                WaitlistPositions::renumber($locked->commute_offer_id);
                SeatOfferClosure::closeForRequest((string) $locked->getKey(), SeatOfferClosedReason::RequestChanged);
            }

            return [$locked, $wasWaiting];
        });

        [$updated, $wasWaiting] = $changed;

        if ($wasWaiting && $offer->driverProfile?->user !== null) {
            Notifier::send($offer->driverProfile->user, NotificationType::WaitlistApplicationReceived,
                ['name' => $updated->passenger?->public_first_name ?? ''],
                ['seatRequestId' => $updated->id, 'kind' => SeatRequestKind::WaitlistApplication->value],
            );
        }

        return $updated;
    }

    private function assertDaysFit(SeatRequest $request, CommuteOffer $offer, int $days): void
    {
        if ($request->commitment !== SeatRequestCommitment::Recurring) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'requestedDaysMask' => [__('validation.prohibited', ['attribute' => 'requestedDaysMask'])],
            ]);
        }

        $offered = $offer->schedule?->days_mask;

        if ($offered === null || ($days & ~$offered) !== 0) {
            throw DomainException::of(ErrorCode::RecurringDaysNotOffered, fields: [
                'requestedDaysMask' => [(string) ($offered ?? 0)],
            ]);
        }
    }

    private static function isEditable(SeatRequestStatus $status): bool
    {
        return $status === SeatRequestStatus::Pending || $status === SeatRequestStatus::Waitlisted;
    }
}
