<?php

namespace App\Http\Controllers\Api\V1\Trip;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Actions\AdvanceTripAction;
use App\Domains\Trip\Actions\ConfirmAttendanceAction;
use App\Domains\Trip\Actions\DisputeAttendanceAction;
use App\Domains\Trip\Models\Attendance;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Trip\CheckInPassengerRequest;
use App\Http\Requests\Trip\DisputeAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who actually travelled (Chapter 8's Attendance section, decision D18).
 *
 * 🔒 The two sides of D18 are the two halves of this controller, and they are deliberately
 * asymmetric:
 *
 * - The DRIVER records. She is the only one in a position to know who got in, and Chapter 8
 *   chose her over QR codes and GPS because those fail on a flat phone and in a basement.
 * - The PASSENGER contests. That is not a courtesy — it is what makes one party deciding
 *   the other's bill acceptable at all (Master Plan §15.6), and it is why the dispute
 *   endpoint exists in the same slice as the confirmation rather than a phase later.
 *
 * Neither can do the other's half. A passenger cannot mark themselves present, and a
 * driver cannot dismiss a dispute — that goes to somebody who is neither of them.
 */
final class AttendanceController extends Controller
{
    /**
     * GET /v1/trips/{trip}/attendance — who is aboard, to the driver running it.
     *
     * The driver's own list during the run. Not offered to passengers: the other people in
     * the car are a group's business (the group endpoints serve that), and who was marked
     * absent this morning is not something to hand a fellow passenger.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function index(Request $request, string $trip): JsonResponse
    {
        $scheduled = $this->driven($request, $trip);

        $rows = Attendance::query()
            ->whereIn('booking_id', Booking::query()
                ->where('scheduled_trip_id', $scheduled->id)
                ->select('id'))
            ->with('booking.passenger.stats', 'booking.passenger.verifications')
            // Stable: `attendance` is keyed by booking id, so ordering by it is both
            // deterministic and roughly the order the seats were approved in.
            ->orderBy('booking_id')
            ->get();

        return ApiResponse::success(AttendanceResource::collection($rows));
    }

    /**
     * POST /v1/trips/{trip}/check-in — "Sara arrived".
     */
    #[ApiErrors(
        ErrorCode::TripNotStarted,
        ErrorCode::AttendanceNotConfirmable,
        ErrorCode::NotFound,
    )]
    public function checkIn(CheckInPassengerRequest $request, string $trip, ConfirmAttendanceAction $action): JsonResponse
    {
        $scheduled = $this->driven($request, $trip);
        $session = AdvanceTripAction::sessionFor($scheduled);

        $attendance = $action->present(
            $session,
            $this->bookingOn($scheduled, $request->string('bookingId')->value()),
            confirmedBy: $request->user()->id,
            at: $this->reportedPosition($request),
        );

        return ApiResponse::success(new AttendanceResource($attendance));
    }

    /**
     * POST /v1/trips/{trip}/no-show — the passenger did not come.
     *
     * Its own endpoint rather than a status on check-in, because it is a different act with
     * a different consequence: one puts somebody in a car, the other puts a mark on their
     * record. A single endpoint switching on an enum makes those one typo apart.
     */
    #[ApiErrors(
        ErrorCode::TripNotStarted,
        ErrorCode::AttendanceNotConfirmable,
        ErrorCode::NotFound,
    )]
    public function noShow(CheckInPassengerRequest $request, string $trip, ConfirmAttendanceAction $action): JsonResponse
    {
        $scheduled = $this->driven($request, $trip);
        $session = AdvanceTripAction::sessionFor($scheduled);

        $attendance = $action->noShow(
            $session,
            $this->bookingOn($scheduled, $request->string('bookingId')->value()),
            confirmedBy: $request->user()->id,
        );

        return ApiResponse::success(new AttendanceResource($attendance));
    }

    /**
     * POST /v1/bookings/{booking}/dispute — "That's not right".
     *
     * 🔒 Addressed by BOOKING rather than by trip, because the person raising it is
     * disputing their own seat and nothing else. A trip-scoped route would have to take a
     * booking id as well, and then answer what happens when somebody sends one that is not
     * theirs.
     */
    #[ApiErrors(
        ErrorCode::AttendanceNotConfirmable,
        ErrorCode::AttendanceDisputeWindowClosed,
        ErrorCode::NotFound,
    )]
    public function dispute(DisputeAttendanceRequest $request, string $booking, DisputeAttendanceAction $action): JsonResponse
    {
        $own = Booking::query()
            ->whereKey($booking)
            ->where('passenger_user_id', $request->user()->id)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return ApiResponse::success(new AttendanceResource(
            $action->execute($own, $request->string('reason')->value())
        ));
    }

    /**
     * Where the driver's phone says they are, if it said anything.
     */
    private function reportedPosition(Request $request): ?Coordinate
    {
        if ($request->input('lat') === null) {
            return null;
        }

        return new Coordinate((float) $request->input('lat'), (float) $request->input('lng'));
    }

    /**
     * A seat on THIS run. 404 for a booking from another day or another commute, so a
     * booking id cannot be probed by pointing it at a trip the caller does drive.
     */
    private function bookingOn(ScheduledTrip $trip, string $bookingId): Booking
    {
        return Booking::query()
            ->whereKey($bookingId)
            ->where('scheduled_trip_id', $trip->id)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
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
            ->with('tripSession')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
