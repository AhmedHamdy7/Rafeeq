<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Actions\CreateCommuteOfferAction;
use App\Domains\Commute\Actions\DuplicateCommuteOfferAction;
use App\Domains\Commute\Actions\PublishCommuteOfferAction;
use App\Domains\Commute\Actions\SaveCommuteRouteAction;
use App\Domains\Commute\Actions\SaveCommuteScheduleAction;
use App\Domains\Commute\Enums\CommuteDirection;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Http\Controllers\Controller;
use App\Http\Resources\CommuteOfferResource;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/testing/commutes/linked-pair — a published Going commute (arrives 08:15) and its linked
 * Return (leaves 17:00), Sunday to Thursday, for the signed-in approved driver (S6, S9, S10).
 *
 * `passengerUserId` (optional) gets a confirmed seat on the next day of both, so "Also on your
 * return" and the ride-back suggestion can be checked without building the pair by hand.
 */
final class TestingReturnTripsController extends Controller
{
    public function linkedPair(
        Request $request,
        CreateCommuteOfferAction $create,
        SaveCommuteRouteAction $route,
        SaveCommuteScheduleAction $schedule,
        PublishCommuteOfferAction $publish,
        DuplicateCommuteOfferAction $duplicate,
    ): JsonResponse {
        $data = $request->validate([
            'passengerUserId' => ['sometimes', 'nullable', 'string', 'size:26', 'exists:users,id'],
            'arriveBy' => ['sometimes', 'string', 'date_format:H:i'],
            'leaveAt' => ['sometimes', 'string', 'date_format:H:i'],
        ]);

        $profile = DriverProfile::query()->whereKey($request->user()->id)->first()
            ?? throw DomainException::of(ErrorCode::DriverNotEligible);

        $going = $create->execute($profile, ['direction' => CommuteDirection::Going->value, 'commuteType' => 'recurring']);
        $route->execute($going, [
            'origin' => ['lat' => 30.0594, 'lng' => 31.4913, 'address' => 'Rehab City'],
            'destination' => ['lat' => 30.0714, 'lng' => 30.9716, 'address' => 'Smart Village'],
        ]);
        $schedule->execute($going->refresh(), [
            'daysMask' => DaysMask::weekdaysSunToThu()->value,
            'promisedArrivalTime' => ($data['arriveBy'] ?? '08:15').':00',
            'startDate' => CarbonImmutable::tomorrow()->toDateString(),
        ]);
        $going = $publish->execute($going->refresh());

        $return = $duplicate->reverse($profile, $going->refresh()->load(['locations', 'schedule', 'rules', 'weekdaySchedules']));
        $schedule->execute($return->refresh(), [
            'daysMask' => DaysMask::weekdaysSunToThu()->value,
            'promisedDepartureTime' => ($data['leaveAt'] ?? '17:00').':00',
            'startDate' => CarbonImmutable::tomorrow()->toDateString(),
        ]);
        $return = $publish->execute($return->refresh());

        /** @var list<string> $bookings */
        $bookings = [];
        $passengerId = $data['passengerUserId'] ?? null;

        if ($passengerId !== null) {
            $passenger = User::query()->findOrFail($passengerId);
            foreach ([$going, $return] as $offer) {
                $seat = $this->seat($passenger, $offer);

                if ($seat !== null) {
                    $bookings[] = $seat;
                }
            }
        }

        $load = ['locations', 'schedule', 'weekdaySchedules', 'rules', 'scheduledTrips'];

        return ApiResponse::success([
            'going' => new CommuteOfferResource($going->refresh()->load($load)),
            'return' => new CommuteOfferResource($return->refresh()->load($load)),
            'bookingIds' => $bookings,
        ], status: 201);
    }

    private function seat(User $passenger, CommuteOffer $offer): ?string
    {
        $trip = ScheduledTrip::query()
            ->where('commute_offer_id', $offer->getKey())
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->orderBy('departure_at')
            ->first();

        if ($trip === null) {
            return null;
        }

        $origin = $offer->locations()->orderBy('sequence')->first();
        $destination = $offer->locations()->orderByDesc('sequence')->first();

        $booking = new Booking;
        $booking->forceFill([
            'scheduled_trip_id' => $trip->id,
            'passenger_user_id' => $passenger->id,
            'driver_profile_id' => $offer->driver_profile_id,
            'seats_reserved' => 1,
            'price_snapshot_piastres' => (int) $trip->seat_price_piastres,
            'platform_fee_snapshot_piastres' => (int) $trip->commission_piastres,
            'driver_amount_snapshot_piastres' => (int) $trip->driver_share_piastres,
            'payment_type' => PaymentType::Cash,
            'payment_status' => PaymentStatus::NotDue,
            'status' => BookingStatus::Confirmed,
            'pickup_point' => new Coordinate(lat: (float) $origin->lat, lng: (float) $origin->lng),
            'pickup_lat' => $origin->lat,
            'pickup_lng' => $origin->lng,
            'pickup_label' => $origin->address,
            'dropoff_point' => new Coordinate(lat: (float) $destination->lat, lng: (float) $destination->lng),
            'dropoff_lat' => $destination->lat,
            'dropoff_lng' => $destination->lng,
            'dropoff_label' => $destination->address,
            'cancellation_fee_piastres' => 0,
        ])->save();

        $trip->increment('seats_taken');

        return (string) $booking->id;
    }
}
