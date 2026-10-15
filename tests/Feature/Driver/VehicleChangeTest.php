<?php

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
 * Product decision, 2026-10-16: **confirmed future bookings are kept, not cancelled** — the
 * passengers are notified instead, and the notification names the new car.
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
 * 🔴 The product decision, asserted: the booking survives and the passenger is told which car.
 */
it('keeps a confirmed booking and tells the passenger which car is coming', function () {
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

    // The seat is untouched.
    expect(test()->withToken($paxToken)->getJson("/api/v1/bookings/{$bookingId}")->assertOk()->json('data.status'))
        ->toBe('CONFIRMED');

    $told = Notification::query()->where('type', 'booking_vehicle_changed')->get();

    expect($told)->toHaveCount(1);

    /*
     * 🔴 The plate is IN the message, not left for her to go and look up. She is going to stand at
     * a kerb and get into whichever car matches what she was told.
     */
    expect($told->first()->body)->toContain('ق ن ر 7788')
        ->and($told->first()->body)->toContain('Cerato');
});

/**
 * 🔴 The edge case that would otherwise oversubscribe a car, or silently cancel somebody.
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
