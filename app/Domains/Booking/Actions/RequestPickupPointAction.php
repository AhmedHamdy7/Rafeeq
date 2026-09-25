<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\PickupPointRequestStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\PickupDetour;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Could you pick me up here instead?"
 *
 * Two people ask this, and the schema keeps them apart with two nullable keys:
 * somebody still joining (`seat_request_id`) and somebody already in the group
 * (`group_member_id`, which ERD §23.1 added specifically for the group screen's
 * "suggest a different meeting point"). Both funnel into one row and one answer
 * from the driver, because from the driver's side it is the same question.
 *
 * 🔴 The cost of the stop is computed here and never accepted from the request —
 * see {@see PickupDetour}. It is then checked against the driver's own
 * `max_detour_minutes` before the request is created at all: a proposal beyond the
 * limit they already set is not a decision to hand them, it is one they made when
 * they published.
 *
 * `effective_from` is recorded as `next_trip` only. The ERD lists a second value,
 * `specific_date`, but there is no column to hold WHICH date — so accepting it
 * would either store a date in a field documented to hold a keyword, or accept a
 * value that silently does the same thing as `next_trip`. Neither is honest, and
 * the schema change that would settle it is not one the ERD specifies. Noted in
 * the progress file rather than guessed at.
 */
final readonly class RequestPickupPointAction
{
    public function __construct(private GeoQueryEngine $geo) {}

    /**
     * Somebody still joining: the point is attached to their open seat request, so
     * the driver reads the request and the meeting point together.
     *
     * @param  array<string, mixed>  $input  validated upstream
     */
    public function forSeatRequest(SeatRequest $request, array $input): PickupPointRequest
    {
        if ($request->status !== SeatRequestStatus::Pending
            && $request->status !== SeatRequestStatus::Waitlisted) {
            // An answered request is not a conversation any more.
            throw DomainException::of(ErrorCode::SeatRequestNotPending);
        }

        $this->assertNothingPending(PickupPointRequest::query()->where('seat_request_id', $request->id));

        return $this->create(
            offer: $request->commuteOffer,
            requesterUserId: $request->passenger_user_id,
            input: $input,
            seatRequestId: $request->id,
            groupMemberId: null,
        );
    }

    /**
     * Somebody already riding. Their bookings exist, so an approval changes where
     * they are collected from tomorrow onwards.
     *
     * @param  array<string, mixed>  $input  validated upstream
     */
    public function forMember(GroupMember $member, array $input): PickupPointRequest
    {
        if (! $member->isActive()) {
            throw DomainException::of(ErrorCode::GroupNotActive);
        }

        $this->assertNothingPending(PickupPointRequest::query()->where('group_member_id', $member->id));

        return $this->create(
            offer: $member->commuteGroup->commuteOffer,
            requesterUserId: $member->user_id,
            input: $input,
            seatRequestId: null,
            groupMemberId: $member->id,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function create(
        CommuteOffer $offer,
        string $requesterUserId,
        array $input,
        ?string $seatRequestId,
        ?string $groupMemberId,
    ): PickupPointRequest {
        $offer->loadMissing('locations');

        /*
         * The driver's own switch, checked before anything is measured. A commute
         * published with `allows_custom_pickup` off is one whose driver said they
         * stop where they stop — asking them anyway would put a decision in front
         * of them that they already made, every morning, for every passenger.
         */
        if (! $offer->allows_custom_pickup) {
            throw DomainException::of(ErrorCode::PickupNotOnCommute);
        }

        $proposed = new Coordinate((float) $input['lat'], (float) $input['lng']);

        $detour = PickupDetour::measure($this->geo, $offer, $proposed);
        $detour->assertWithin($offer->max_detour_minutes);

        $pickup = new PickupPointRequest;

        $pickup->fill([
            'seat_request_id' => $seatRequestId,
            'group_member_id' => $groupMemberId,
            'requested_by_user_id' => $requesterUserId,
            'proposed_point' => $proposed,
            'proposed_label' => $input['label'] ?? null,
            'effective_from' => 'next_trip',
        ]);

        // Not fillable, and set from the measurement rather than the input. See the
        // model for why that distinction is load-bearing.
        $pickup->added_minutes = $detour->addedMinutes;
        $pickup->added_km = $detour->addedKm;
        $pickup->status = PickupPointRequestStatus::Pending->value;

        $pickup->save();

        return $pickup;
    }

    /**
     * One open proposal at a time. Without this a passenger could queue five
     * points and leave the driver to work out which one they actually want.
     *
     * @param  Builder<PickupPointRequest>  $scope
     */
    private function assertNothingPending($scope): void
    {
        $exists = $scope
            ->whereIn('status', [
                PickupPointRequestStatus::Pending->value,
                // A suggested alternative is still an open conversation: the
                // passenger has an answer waiting for THEM.
                PickupPointRequestStatus::SuggestedAlternative->value,
            ])
            ->exists();

        if ($exists) {
            throw DomainException::of(ErrorCode::PickupAlreadyRequested);
        }
    }
}
