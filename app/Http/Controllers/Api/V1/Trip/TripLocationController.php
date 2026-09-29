<?php

namespace App\Http\Controllers\Api\V1\Trip;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Actions\AdvanceTripAction;
use App\Domains\Trip\Actions\RecordTripLocationAction;
use App\Domains\Trip\ValueObjects\TripPosition;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Trip\RecordTripLocationRequest;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the car is (Chapter 8's Live Location).
 *
 * 🔒 The most privacy-sensitive endpoint pair in the product, so the rules are narrow:
 *
 * - The DRIVER reports. Nobody else can, and there is no field anywhere that lets a client
 *   claim a position on somebody else's behalf.
 * - A PASSENGER on the run reads the CURRENT position, and only while the run is under way.
 *   That is the whole of what a live map needs: she is standing on a street corner waiting
 *   for a car.
 * - Nobody reads the TRAIL. `trip_locations` is a minute-by-minute record of where a real
 *   person was, kept solely because a no-show dispute has no other evidence. No endpoint
 *   here returns it, at any access level — support reads it through the dashboard, and it is
 *   deleted after ninety days.
 *
 * 🔴 Why a polling endpoint exists at all when there is a WebSocket. The broadcast is the
 * fast path and this is the honest fallback: a phone on a bad connection at a bus stop is
 * exactly the situation a live map is for, and it is also the situation where a socket does
 * not connect. A design that only pushed would work perfectly everywhere except where it
 * matters.
 */
final class TripLocationController extends Controller
{
    /**
     * POST /v1/trips/{trip}/location — the driver's phone reporting in.
     *
     * Answers with how many points were kept, not with the positions back. A client does not
     * need its own readings returned, and the count is the one thing it cannot work out: it
     * says that some of what it sent was implausible and dropped.
     */
    #[ApiErrors(ErrorCode::TripNotStarted, ErrorCode::NotFound)]
    public function store(RecordTripLocationRequest $request, string $trip, RecordTripLocationAction $action): JsonResponse
    {
        $session = AdvanceTripAction::sessionFor($this->driven($request, $trip));

        $positions = [];

        foreach ($request->array('points') as $point) {
            $positions[] = new TripPosition(
                lat: (float) $point['lat'],
                lng: (float) $point['lng'],
                recordedAt: CarbonImmutable::parse($point['recordedAt']),
                accuracyMeters: isset($point['accuracyMeters']) ? (int) $point['accuracyMeters'] : null,
                speedKmh: isset($point['speedKmh']) ? (int) $point['speedKmh'] : null,
            );
        }

        $accepted = $action->execute($session, $positions);

        return ApiResponse::success([
            'accepted' => count($accepted),
            /*
             * Stated rather than left to be inferred from the difference: a client that sent
             * six and had two dropped should be able to log that plainly, because the usual
             * cause is a phone clock that is wrong and the driver will never notice.
             */
            'rejected' => count($positions) - count($accepted),
        ]);
    }

    /**
     * GET /v1/trips/{trip}/location — the current position, to the driver or anybody on the
     * run.
     *
     * Null when the run has gone quiet, which is a real answer rather than a missing one: a
     * car in a tunnel stops reporting, and `lastLocationAt` on the trip says how long the
     * silence has lasted. A stale dot is worse than none — a passenger who sees a car that
     * stopped reporting twenty minutes ago will walk towards it.
     */
    #[ApiErrors(ErrorCode::TripNotStarted, ErrorCode::NotFound)]
    public function show(Request $request, string $trip, RecordTripLocationAction $action): JsonResponse
    {
        $session = AdvanceTripAction::sessionFor($this->visible($request, $trip));

        $position = $action->current($session);

        return ApiResponse::success([
            'position' => $position?->toArray(),
            // When the last reading arrived, whether or not one is still current — this is
            // how a client knows its map is out of date rather than that the car has stopped.
            'lastLocationAt' => $session->last_location_at?->toIso8601String(),
            'isUnderway' => $session->current_status->isUnderway(),
        ]);
    }

    /**
     * A day of the caller's OWN commute. 404 for anybody else's, drivers included.
     */
    private function driven(Request $request, string $tripId): ScheduledTrip
    {
        return ScheduledTrip::query()
            ->whereKey($tripId)
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $request->user()->id)
                ->select('id'))
            ->with('tripSession.scheduledTrip.commuteOffer')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    /**
     * 🔒 A run the caller may watch: theirs to drive, or one they hold a LIVE seat on.
     *
     * Narrower than the rule for reading the trip itself, which also admits a completed
     * booking so the dispute window works. A position is different: somebody who has finished
     * their journey has no reason to keep watching the car, and for most commutes where it
     * goes next is the driver's home.
     */
    private function visible(Request $request, string $tripId): ScheduledTrip
    {
        $trip = ScheduledTrip::query()
            ->whereKey($tripId)
            ->with(['commuteOffer', 'tripSession.scheduledTrip.commuteOffer'])
            ->first();

        if ($trip === null) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        if ($trip->commuteOffer->driver_profile_id === $request->user()->id) {
            return $trip;
        }

        $ridesOnIt = Booking::query()
            ->where('scheduled_trip_id', $trip->id)
            ->where('passenger_user_id', $request->user()->id)
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
            ->exists();

        return $ridesOnIt ? $trip : throw DomainException::of(ErrorCode::NotFound);
    }
}
