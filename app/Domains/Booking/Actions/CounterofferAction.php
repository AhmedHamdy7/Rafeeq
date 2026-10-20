<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\PointSource;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\RequestedPoints;
use App\Domains\Booking\Support\SeatApproval;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use Illuminate\Support\Facades\DB;

/**
 * The driver's third answer to a seat request, and the passenger's answer back (Feature 03 v5 §2).
 *
 * > *"The driver must review them and explicitly **Accept**, **Reject**, or **Counteroffer** a
 * > different pickup/drop-off. A counteroffer requires passenger's explicit in-app acceptance; chat
 * > is not consent. Only after confirmation do the passenger's **agreed** pickup/drop-off become
 * > committed route stops."*
 *
 * 🔴 **"Chat is not consent" is the whole design, and it is why this is a structured exchange
 * rather than a conversation.** Two people can agree a street corner in a chat thread in ten
 * seconds, and nothing in the system would know: the itinerary would still send the driver to the
 * original point, the attendance geofence would still measure against it, and a dispute about a
 * missed pickup would be settled against a record that disagreed with what both of them remembered
 * agreeing. So the proposal is a row, the acceptance is a row, and the committed point only moves
 * when the second one exists.
 *
 * 🔴 **Accepting the counteroffer confirms the seat.** The driver proposed the place, so she has
 * already said yes on those terms — asking her to approve again would be asking the same question
 * twice and leaving the passenger waiting a second time for an answer that was given. But it runs
 * through {@see ApproveSeatRequestAction}, under its row lock, because the seat may be gone by then:
 * her counteroffer is a promise about a MEETING PLACE, never about a seat still being free.
 *
 * ⚠️ Separate from {@see PickupPointRequest}, which looks like the same
 * thing and is not. That is for somebody who already HAS a seat asking to be collected elsewhere,
 * with the driver countering. This is the driver countering a request that has no seat yet, on
 * either or both points, before anybody has agreed to anything.
 */
final readonly class CounterofferAction
{
    public function __construct(
        private GeoQueryEngine $geo,
        private ApproveSeatRequestAction $approve,
    ) {}

    /**
     * The driver proposes a different pickup, drop-off, or both.
     *
     * @param  array<string, mixed>  $input  validated upstream: `pickup` and/or `dropoff`, plus `note`
     */
    public function propose(SeatRequest $request, User $driver, array $input): SeatRequest
    {
        /*
         * Pending only.
         *
         * Not a waitlisted request: she is on a list because there was no seat, and countering her
         * meeting point would answer a question nobody asked while leaving her on the list. When a
         * seat frees she is offered it on the terms already recorded — and the driver can counter
         * then, because the request goes back through this path only while it is pending.
         */
        if ($request->status !== SeatRequestStatus::Pending) {
            throw DomainException::of(ErrorCode::SeatRequestNotPending);
        }

        $offer = $request->commuteOffer;

        if (! $offer instanceof CommuteOffer) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        $offer->loadMissing('locations');

        /*
         * The pair she is actually proposing: whichever point she named, plus the passenger's own
         * for the other end.
         *
         * 🔴 Validated as a PAIR, not as one new point. A driver moving only the drop-off can still
         * produce a journey that runs backwards or a detour past her own limit, because what makes
         * a pair workable is the relationship between the two — and the passenger's existing point
         * is half of it.
         */
        [$pickup, $pickupLabel, $pickupMoved] = $this->proposedPoint(
            $offer, $input['pickup'] ?? null, CommuteLocationType::Pickup,
            new Coordinate((float) $request->requested_pickup_lat, (float) $request->requested_pickup_lng),
            $request->requested_pickup_label,
        );

        [$dropoff, $dropoffLabel, $dropoffMoved] = $this->proposedPoint(
            $offer, $input['dropoff'] ?? null, CommuteLocationType::Dropoff,
            new Coordinate((float) $request->requested_dropoff_lat, (float) $request->requested_dropoff_lng),
            $request->requested_dropoff_label,
        );

        if (! $pickupMoved && ! $dropoffMoved) {
            /*
             * Nothing was actually moved — she named the points the passenger already asked for.
             * Refused rather than recorded, because a counteroffer the passenger has to accept in
             * order to get exactly what she requested is a round trip that changes nothing and
             * costs her a decision.
             */
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'pickup' => [__('validation.custom.counteroffer.required')],
            ]);
        }

        RequestedPoints::assertUsablePair($this->geo, $offer, $pickup, $dropoff);

        return DB::transaction(function () use ($request, $driver, $input, $pickup, $pickupLabel, $pickupMoved, $dropoff, $dropoffLabel, $dropoffMoved): SeatRequest {
            $request->forceFill([
                'status' => SeatRequestStatus::Counteroffered->value,
                'responded_by' => $driver->id,
                'responded_at' => now(),
                'counteroffered_at' => now(),
                'counteroffered_by' => $driver->id,
                'counteroffer_note' => $input['note'] ?? null,
                'counteroffer_answered_at' => null,

                /*
                 * Only the point she actually moved is recorded. Writing both would make a
                 * counteroffer that touched the drop-off look as though the pickup had been
                 * renegotiated too, and the passenger would be asked to re-agree to her own choice.
                 */
                'counteroffer_pickup_point' => $pickupMoved ? $pickup : null,
                'counteroffer_pickup_lat' => $pickupMoved ? $pickup->lat : null,
                'counteroffer_pickup_lng' => $pickupMoved ? $pickup->lng : null,
                'counteroffer_pickup_label' => $pickupMoved ? $pickupLabel : null,

                'counteroffer_dropoff_point' => $dropoffMoved ? $dropoff : null,
                'counteroffer_dropoff_lat' => $dropoffMoved ? $dropoff->lat : null,
                'counteroffer_dropoff_lng' => $dropoffMoved ? $dropoff->lng : null,
                'counteroffer_dropoff_label' => $dropoffMoved ? $dropoffLabel : null,

                /*
                 * The clock restarts, as it does when a driver waitlists somebody: the 48 hours the
                 * request had were for the driver to answer, and she just did. Expiring it on the
                 * old deadline would close a conversation the passenger had minutes to join.
                 */
                'expires_at' => now()->addHours((int) config('rafeeq.booking.request_expiry_hours')),
            ])->save();

            Notifier::send($request->passenger, NotificationType::SeatCounteroffered, params: [
                'name' => $driver->public_first_name,
            ], data: [
                'seatRequestId' => $request->id,
                'commuteId' => $request->commute_offer_id,
            ]);

            return $request;
        });
    }

    /**
     * The passenger accepting the place the driver proposed — which confirms her seat.
     *
     * 🔴 The counteroffered points become the AGREED points first, then the ordinary approval runs.
     * That ordering matters: {@see CreateBookingAction} snapshots `requested_*` onto the booking, so
     * approving before the move would freeze the original points onto a booking whose whole purpose
     * was to record the agreed ones.
     */
    public function accept(SeatRequest $request, User $passenger): SeatApproval
    {
        $this->assertHerOwnLiveCounteroffer($request, $passenger);

        $driver = $request->counterofferedBy;

        if ($driver === null) {
            // A counteroffer with no author cannot be approved on anybody's behalf.
            throw DomainException::of(ErrorCode::NotFound);
        }

        $moved = [
            'counteroffer_answered_at' => now(),
            /*
             * `pending` for the instant between here and the approval below. The approval refuses
             * anything else, and setting it inside the same transaction means no reader ever sees a
             * request that is neither counteroffered nor approved.
             */
            'status' => SeatRequestStatus::Pending->value,
        ];

        foreach (['pickup', 'dropoff'] as $kind) {
            if ($request->{"counteroffer_{$kind}_lat"} === null) {
                // She did not move this end, so the passenger's own choice stands.
                continue;
            }

            $moved["requested_{$kind}_point"] = new Coordinate(
                (float) $request->{"counteroffer_{$kind}_lat"},
                (float) $request->{"counteroffer_{$kind}_lng"},
            );
            $moved["requested_{$kind}_lat"] = $request->{"counteroffer_{$kind}_lat"};
            $moved["requested_{$kind}_lng"] = $request->{"counteroffer_{$kind}_lng"};
            $moved["requested_{$kind}_label"] = $request->{"counteroffer_{$kind}_label"};
            /*
             * 🔴 `driver_counteroffer`, not `custom_map`. The agreed place was the DRIVER's idea,
             * and a dispute about somebody waiting at the wrong corner turns on whose idea it was.
             * Recording it as the passenger's own pin would quietly move the responsibility.
             */
            $moved["requested_{$kind}_source"] = PointSource::DriverCounteroffer->value;
            $moved["requested_{$kind}_suggestion_id"] = null;
        }

        return DB::transaction(function () use ($request, $driver, $moved): SeatApproval {
            $request->forceFill($moved)->save();

            /*
             * Through the ordinary approval, under its row lock. Her counteroffer promised a
             * MEETING PLACE, never that a seat would still be free — somebody else may have taken
             * the last one while this conversation was open, and the honest answer then is
             * `SEAT_UNAVAILABLE` rather than an eleventh booking in a ten-seat car.
             */
            return $this->approve->execute($request->refresh(), $driver);
        });
    }

    /**
     * The passenger refusing the place the driver proposed.
     *
     * 🔴 Its own status, and the reason is whose record it lands on. `rejected` would read as the
     * driver refusing her and would inflate a refusal rate for something the passenger decided;
     * `withdrawn` would read as backing out before an answer, when an answer is exactly what she
     * is responding to. Neither is true, and both are read by somebody deciding whether to accept
     * the other person in future.
     *
     * It closes the conversation rather than returning to pending. Going back would show the driver
     * the same two points she had just declined, and the pair would loop.
     */
    public function decline(SeatRequest $request, User $passenger): SeatRequest
    {
        $this->assertHerOwnLiveCounteroffer($request, $passenger);

        $request->forceFill([
            'status' => SeatRequestStatus::CounterofferDeclined->value,
            'counteroffer_answered_at' => now(),
        ])->save();

        if ($request->counterofferedBy !== null) {
            Notifier::send($request->counterofferedBy, NotificationType::SeatCounterofferDeclined, params: [
                'name' => $passenger->public_first_name,
            ], data: [
                'seatRequestId' => $request->id,
            ]);
        }

        return $request;
    }

    /**
     * One proposed end: the driver's new point, or the passenger's existing one if she left it.
     *
     * @param  array<string, mixed>|null  $input
     * @return array{0: Coordinate, 1: string|null, 2: bool} point, label, and whether it moved
     */
    private function proposedPoint(
        CommuteOffer $offer,
        ?array $input,
        CommuteLocationType $kind,
        Coordinate $current,
        ?string $currentLabel,
    ): array {
        if ($input === null) {
            return [$current, $currentLabel, false];
        }

        if (isset($input['suggestionId'])) {
            /*
             * 🔒 Among THIS commute's advertised stops of THIS kind. A driver cannot counteroffer
             * with somebody else's suggestion id, and her own start and end are excluded by the
             * type filter — v5 §1 keeps those as her journey's endpoints rather than meeting
             * places she has offered.
             */
            $suggestion = $offer->locations
                ->where('type', $kind)
                ->firstWhere('id', $input['suggestionId']);

            if ($suggestion === null) {
                throw DomainException::of(ErrorCode::SeatRequestSuggestionUnknown, fields: [
                    $kind->value => [(string) $input['suggestionId']],
                ]);
            }

            return [
                new Coordinate((float) $suggestion->lat, (float) $suggestion->lng),
                $input['label'] ?? $suggestion->address,
                true,
            ];
        }

        $proposed = new Coordinate((float) $input['lat'], (float) $input['lng']);

        /*
         * "Moved" is measured, not assumed from the field being present. A client that echoes the
         * passenger's own coordinates back — a map screen that opened on her pin and was submitted
         * untouched — must not create a counteroffer that asks her to agree to what she already
         * asked for.
         */
        $moved = ! $this->geo->distanceMeters($current, $proposed)
            ->isWithin(Distance::fromMetres(50));

        return [$proposed, $input['label'] ?? null, $moved];
    }

    /**
     * 🔒 Hers, and still open. A 404 for anybody else's request rather than a 403, because a 403
     * would confirm the id exists — and a seat request names who is trying to ride with whom.
     */
    private function assertHerOwnLiveCounteroffer(SeatRequest $request, User $passenger): void
    {
        if ($request->passenger_user_id !== $passenger->id) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        if ($request->status !== SeatRequestStatus::Counteroffered) {
            throw DomainException::of(ErrorCode::SeatRequestNotPending);
        }

        if ($request->expires_at !== null && $request->expires_at->isPast()) {
            // Answered too late. Refused rather than honoured, because the driver's offer of a
            // place was open for a stated time and she may have filled the seat since.
            throw DomainException::of(ErrorCode::SeatRequestNotPending);
        }
    }
}
