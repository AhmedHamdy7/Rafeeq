<?php

use App\Domains\Booking\Enums\DisplacementReason;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Notification\Models\Notification;

/**
 * Feature 02 v3: "vehicle replacement = new **re-verification request**; no multi-vehicle
 * switching. Keep old history; **prevent new driving on unapproved replacement**. Existing commute
 * vehicle associations need integrity checks."
 *
 * 🔴 Feature 03 v5 (2026-10-18) decides what happens to the seats, and it **reverses** the product
 * decision of 2026-10-16 that this file used to assert: confirmed future bookings are now
 * CANCELLED, not kept, and the displaced riders go onto preserved waiting lists.
 *
 * 🔴 What this file does NOT need to test, because reading the code first showed it was already
 * handled: a commute is bound to a specific `vehicle_id`, and `usableVehicle()` is re-run at both
 * publish and trip start. So an unapproved car can never be silently substituted into a journey —
 * the run refuses instead. The gap was narrower and more boring: activation let a driver take her
 * own working commutes offline with one tap and tell nobody.
 */
function secondVehicleFor(string $token, bool $approved): Vehicle
{
    test()->withToken($token)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Kia', 'model' => 'Cerato', 'year' => 2021, 'colour' => 'white',
        'plateNumber' => 'ق ن ر 7788', 'seats' => 5,
        'transmission' => 'automatic', 'fuelType' => 'petrol',
    ])->assertStatus(201);

    $vehicle = Vehicle::query()->where('plate_number', 'ق ن ر 7788')->sole();

    if ($approved) {
        $vehicle->forceFill([
            'verification_status' => VehicleVerificationStatus::Approved->value,
        ])->save();
    }

    return $vehicle->refresh();
}

/**
 * 🔒 The fix. v3: "prevent new driving on unapproved replacement."
 */
it('refuses to activate a vehicle no reviewer has approved', function () {
    $driverToken = approvedDriver();
    $replacement = secondVehicleFor($driverToken, approved: false);

    $before = Vehicle::query()->where('is_active', true)->sole();

    test()->withToken($driverToken)
        ->postJson("/api/v1/driver/vehicles/{$replacement->getKey()}/activate")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VEHICLE_NOT_ALLOWED');

    // And nothing moved: her working car is still the active one.
    expect(Vehicle::query()->where('is_active', true)->sole()->getKey())->toBe($before->getKey())
        ->and($replacement->refresh()->is_active)->toBeFalse();
});

it('moves her commutes onto an approved replacement and keeps the old vehicle as history', function () {
    $driverToken = approvedDriver();
    $vehicleId = test()->withToken($driverToken)->getJson('/api/v1/driver/vehicles')->json('data.0.id');
    $commuteId = readyCommute($driverToken, $vehicleId);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $replacement = secondVehicleFor($driverToken, approved: true);

    test()->withToken($driverToken)
        ->postJson("/api/v1/driver/vehicles/{$replacement->getKey()}/activate")
        ->assertOk();

    expect(CommuteOffer::findOrFail($commuteId)->vehicle_id)->toBe($replacement->getKey())
        // 🔴 Kept, not deleted. The old car is what carried every completed journey, and its
        // documents are the evidence that it was allowed to.
        ->and(Vehicle::query()->whereKey($vehicleId)->exists())->toBeTrue()
        ->and(Vehicle::findOrFail($vehicleId)->is_active)->toBeFalse();
});

/**
 * 🔴 **The reversal, asserted.** v5: "Replace Vehicle requires new verification and cancels all
 * future confirmed bookings across that driver's commutes; displaced riders enter preserved
 * waitlists."
 *
 * This test replaces one that asserted the opposite — that the booking survived and the passenger
 * was merely told the new plate. The argument that lost: a replacement car starts a fresh
 * verification, and `usableVehicle()` refuses to start a run in a car no reviewer has approved. So
 * the seat that was "kept" was never a seat she could ride in, and she would have discovered that
 * at the kerb instead of now.
 */
it('cancels a confirmed booking when she replaces her car and puts the passenger back on the list', function () {
    $driverToken = approvedDriver();
    $vehicleId = test()->withToken($driverToken)->getJson('/api/v1/driver/vehicles')->json('data.0.id');
    $commuteId = readyCommute($driverToken, $vehicleId);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $paxToken = verifiedPassenger('01099887766');

    // Taken from the generated trips directly, as the booking tests do — reading it out of a
    // search result couples this test to the matcher and fails for the wrong reason.
    $tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    $requestId = requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');
    $bookingId = approveSeat($driverToken, $requestId);

    $replacement = secondVehicleFor($driverToken, approved: true);

    test()->withToken($driverToken)
        ->postJson("/api/v1/driver/vehicles/{$replacement->getKey()}/activate")
        ->assertOk();

    // The seat is gone, and recorded against the driver — she is the one who changed car.
    expect(test()->withToken($paxToken)->getJson("/api/v1/bookings/{$bookingId}")->assertOk()->json('data.status'))
        ->toBe('CANCELLED_BY_DRIVER')
        // The day it freed is free again.
        ->and(ScheduledTrip::findOrFail($tripId)->seats_taken)->toBe(0);

    /*
     * 🔴 She is on the waiting list, not simply refused — and marked as displaced, so the driver's
     * waitlist card can say **Previous Passenger** and she gets first look when the new car clears.
     */
    $waiting = SeatRequest::findOrFail($requestId);

    expect($waiting->status)->toBe(SeatRequestStatus::Waitlisted)
        ->and($waiting->displaced_reason)->toBe(DisplacementReason::VehicleReplaced->value)
        ->and($waiting->displaced_at)->not->toBeNull();

    // Told once, naming the reason, and told she is back on the list.
    $told = Notification::query()->where('type', 'vehicle_replaced_seat_cancelled')->get();

    expect($told)->toHaveCount(1)
        ->and($told->first()->user_id)->toBe(SeatRequest::findOrFail($requestId)->passenger_user_id);
});

/**
 * 🔴 And the freed seat is NOT announced to the waiting list.
 *
 * The ordinary cancellation path offers a released seat to everybody waiting, which is right when
 * one person drops out. Here the car itself is gone — an offer would reach the very woman who just
 * lost her seat, inviting her to take it back in a vehicle nobody has approved.
 */
it('does not offer the freed seats to anybody when the car is replaced', function () {
    $driverToken = approvedDriver();
    $vehicleId = test()->withToken($driverToken)->getJson('/api/v1/driver/vehicles')->json('data.0.id');
    $commuteId = readyCommute($driverToken, $vehicleId, ['seatsTotal' => 2]);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $tripId = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail()->id;

    // One riding, one already waiting behind her.
    $riderToken = verifiedPassenger('01099887766');
    $riderRequest = requestSeat($riderToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');
    approveSeat($driverToken, $riderRequest);

    $waiterToken = verifiedPassenger('01077665544', device: 'pax-2');
    $waiterRequest = requestSeat($waiterToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id');
    test()->withToken($driverToken)->postJson("/api/v1/driver/seat-requests/{$waiterRequest}/waitlist")->assertOk();

    $replacement = secondVehicleFor($driverToken, approved: true);

    test()->withToken($driverToken)
        ->postJson("/api/v1/driver/vehicles/{$replacement->getKey()}/activate")
        ->assertOk();

    expect(SeatOffer::query()->where('status', SeatOfferStatus::Open->value)->count())->toBe(0)
        ->and(Notification::query()->where('type', 'seat_offered')->count())->toBe(0);
});

/**
 * 🔴 The edge case that would otherwise leave a commute advertising more seats than the car has.
 */
it('refuses the whole swap when the replacement has too few seats for a live commute', function () {
    $driverToken = approvedDriver();
    $vehicleId = test()->withToken($driverToken)->getJson('/api/v1/driver/vehicles')->json('data.0.id');

    // Four bookable seats on the original car.
    $commuteId = readyCommute($driverToken, $vehicleId, ['seatsTotal' => 4]);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    test()->withToken($driverToken)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Fiat', 'model' => '500', 'year' => 2022, 'colour' => 'red',
        'plateNumber' => 'ط ح س 1122', 'seats' => 3,
        'transmission' => 'manual', 'fuelType' => 'petrol',
    ])->assertStatus(201);

    $small = Vehicle::query()->where('plate_number', 'ط ح س 1122')->sole();
    $small->forceFill(['verification_status' => VehicleVerificationStatus::Approved->value])->save();

    test()->withToken($driverToken)
        ->postJson("/api/v1/driver/vehicles/{$small->getKey()}/activate")
        ->assertStatus(422);

    /*
     * Nothing half-applied. The alternative to refusing is cancelling a booked seat, which the
     * product decision rules out, or leaving the commute pointing at an inactive car, which breaks
     * it silently — so the driver finds out now, while she can still choose.
     */
    expect(CommuteOffer::findOrFail($commuteId)->vehicle_id)->toBe($vehicleId)
        ->and(Vehicle::findOrFail($vehicleId)->is_active)->toBeTrue()
        ->and($small->refresh()->is_active)->toBeFalse();
});
