<?php

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\PricingSettings;
use App\Domains\Commute\Support\SeatPriceQuote;
use App\Domains\Commute\Support\SeatPricing;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Support\Facades\Storage;

/**
 * The automatic seat price (P-1 → P-5; PAY-41 → PAY-44, PAY-48; D-0016, D-0025, D-0026).
 *
 *     share      = route km × km rate ÷ max(approved seats, 3)        never rounded to the step
 *     commission = max(10% × share, 8 EGP)                              added on top
 *     seat       = share + commission → nearest 5 EGP, halfway down, but up if RAFEEQ < 8 EGP
 *
 * The worked examples are the partners' own, from the Feature 03 backend file §1 (P-2). They are
 * pinned at the shipped beta settings, so a test failing here means either the formula moved or a
 * default did — and both are business decisions, not refactors.
 */
beforeEach(function () {
    Storage::fake('documents');
});

function quoteFor(int $metres, int $approvedSeats, VehicleTier $tier = VehicleTier::Standard): SeatPriceQuote
{
    $vehicle = Vehicle::factory()->make(['approved_seats' => $approvedSeats, 'tier' => $tier]);

    return SeatPricing::quote($metres, $vehicle);
}

/** A share in piastres, as the milli-piastres `seatPriceForShare` takes. */
function seatForShare(int $sharePiastres): int
{
    return SeatPriceQuote::seatPriceForShare(
        $sharePiastres * 1000,
        PricingSettings::commissionRatePercent(),
        PricingSettings::minCommissionPiastres(),
        PricingSettings::roundingStepPiastres(),
    );
}

/*
|--------------------------------------------------------------------------
| The partners' worked examples (P-2)
|--------------------------------------------------------------------------
*/

it('prices 3 seats on 40 km Standard at 110, the driver keeping 100', function () {
    $quote = quoteFor(40_000, 3);

    expect($quote->seatPricePiastres)->toBe(11_000)
        ->and($quote->driverSharePiastres)->toBe(10_000)
        ->and($quote->commissionPiastres)->toBe(1_000);
});

it('prices 4 seats on 40 km Standard at 85: 75 + 8 = 83, to the nearest 5', function () {
    $quote = quoteFor(40_000, 4);

    expect($quote->seatPricePiastres)->toBe(8_500)
        ->and($quote->driverSharePiastres)->toBe(7_500)
        // 7.50 is under the minimum, so 8; the rounding up to 85 is RAFEEQ's too.
        ->and($quote->commissionPiastres)->toBe(1_000);
});

it('prices 3 seats on 8 km at 30: the minimum commission carries a short trip', function () {
    $quote = quoteFor(8_000, 3);

    expect($quote->seatPricePiastres)->toBe(3_000)
        ->and($quote->driverSharePiastres)->toBe(2_000)
        ->and($quote->commissionPiastres)->toBe(1_000);
});

it('rounds a share of 79 up to 90, because 85 would leave RAFEEQ only 6', function () {
    expect(seatForShare(7_900))->toBe(9_000);
});

it('rounds a share of 125 down to 135, because 137.5 is exactly halfway', function () {
    expect(seatForShare(12_500))->toBe(13_500);
});

it('prices 3 seats on 40 km Comfort at 145: 440 ÷ 3 = 146.67, to the nearest 5', function () {
    $quote = quoteFor(40_000, 3, VehicleTier::Comfort);

    expect($quote->seatPricePiastres)->toBe(14_500)
        // The share is never rounded to the step; to the piastre only, the remainder to RAFEEQ.
        ->and($quote->driverSharePiastres)->toBe(13_333)
        ->and($quote->commissionPiastres)->toBe(1_167)
        ->and($quote->kmRatePiastres)->toBe(1_000);
});

/*
|--------------------------------------------------------------------------
| The rules around the examples
|--------------------------------------------------------------------------
*/

/**
 * D-0025 (b): the share is divided by the approved seats but never by fewer than 3, so a car
 * approved for fewer cannot charge each passenger more.
 */
it('never divides by fewer than three seats', function () {
    expect(quoteFor(40_000, 2)->seatPricePiastres)->toBe(quoteFor(40_000, 3)->seatPricePiastres)
        ->and(quoteFor(40_000, 2)->seatDivisor)->toBe(3);
});

it('always adds up: seat = share + commission, and the commission is never under the minimum', function (int $metres, int $seats, VehicleTier $tier) {
    $quote = quoteFor($metres, $seats, $tier);

    expect($quote->seatPricePiastres)->toBe($quote->driverSharePiastres + $quote->commissionPiastres)
        ->and($quote->commissionPiastres)->toBeGreaterThanOrEqual(PricingSettings::minCommissionPiastres())
        ->and($quote->seatPricePiastres % PricingSettings::roundingStepPiastres())->toBe(0);
})->with([
    [1_000, 3, VehicleTier::Standard],
    [12_345, 4, VehicleTier::Standard],
    [33_333, 3, VehicleTier::Comfort],
    [57_891, 5, VehicleTier::Comfort],
    [123_456, 7, VehicleTier::Standard],
]);

/** Every number is an admin setting (ADM-06): the launch moves the commission to 15% (PAY-41). */
it('follows the commission setting', function () {
    setPlatformSetting('pricing.commission_rate_percent', 15);

    $quote = quoteFor(40_000, 3);

    // 100 + 15 = 115, already on a 5-EGP step.
    expect($quote->seatPricePiastres)->toBe(11_500)
        ->and($quote->driverSharePiastres)->toBe(10_000)
        ->and($quote->commissionRatePercent)->toBe(15);
});

it('follows the km rate settings', function () {
    setPlatformSetting('pricing.km_rate_standard_piastres', 900);

    // 40 × 9 ÷ 3 = 120, + 12 = 132 → 130.
    expect(quoteFor(40_000, 3)->seatPricePiastres)->toBe(13_000);
});

/*
|--------------------------------------------------------------------------
| Through the API
|--------------------------------------------------------------------------
*/

/**
 * 🔒 P-1, D-0016: the price is the server's. Refused rather than silently dropped, so an app still
 * on the old contract learns on its first request.
 */
it('refuses a price from the driver on create and on revise', function () {
    $token = approvedDriver();

    createCommute($token, Vehicle::sole()->id, ['pricePerSeatPiastres' => 8000])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['pricePerSeatPiastres']]]);

    $id = createCommute($token, Vehicle::sole()->id)->assertStatus(201)->json('data.id');

    test()->withToken($token)->patchJson("/api/v1/commutes/{$id}", ['pricePerSeatPiastres' => 8000])
        ->assertStatus(422);
});

/**
 * P-5, PAY-44: each day freezes the price AND what it was computed from, at the settings of the
 * moment it was generated.
 */
it('freezes the price and its inputs on every generated day', function () {
    $token = approvedDriver();
    $id = readyCommute($token, Vehicle::sole()->id);

    test()->withToken($token)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    $offer = CommuteOffer::findOrFail($id);
    $expected = SeatPricing::quote((int) $offer->route_distance_meters, Vehicle::sole());
    $trip = ScheduledTrip::query()->orderBy('trip_date')->first();

    expect($trip->seat_price_piastres)->toBe($expected->seatPricePiastres)
        ->and($trip->driver_share_piastres)->toBe($expected->driverSharePiastres)
        ->and($trip->commission_piastres)->toBe($trip->seat_price_piastres - $trip->driver_share_piastres)
        ->and($trip->pricing_route_distance_meters)->toBe((int) $offer->route_distance_meters)
        ->and($trip->pricing_vehicle_tier)->toBe(VehicleTier::Standard)
        ->and($trip->pricing_km_rate_piastres)->toBe(750)
        ->and($trip->pricing_approved_seats)->toBe(3)
        ->and($trip->pricing_seat_divisor)->toBe(3)
        ->and($trip->pricing_commission_rate_percent)->toBe(10)
        ->and($trip->pricing_min_commission_piastres)->toBe(800)
        ->and($trip->pricing_rounding_step_piastres)->toBe(500)
        // And the commute caches the current price for the driver's own screen.
        ->and($offer->seat_price_piastres)->toBe($expected->seatPricePiastres)
        ->and($offer->driver_share_piastres)->toBe($expected->driverSharePiastres);

    // An admin moves the rate. The day that already exists does not move.
    setPlatformSetting('pricing.commission_rate_percent', 15);

    expect($trip->refresh()->pricing_commission_rate_percent)->toBe(10)
        ->and($trip->seat_price_piastres)->toBe($expected->seatPricePiastres);
});

it('shows the driver the price and his share, read-only, before he publishes', function () {
    $token = approvedDriver();
    $id = readyCommute($token, Vehicle::sole()->id);

    $price = test()->withToken($token)->getJson("/api/v1/commutes/{$id}/price")->assertOk()->json('data');

    expect($price['seatPricePiastres'])->toBeInt()->toBeGreaterThan($price['driverSharePiastres'])
        ->and($price['tier'])->toBe('standard')
        ->and($price['approvedSeats'])->toBe(3)
        // 🔒 Never the rate (P-3).
        ->and($price)->not->toHaveKey('commissionRatePercent');

    // Publishing prices the days with the same road and the same car.
    test()->withToken($token)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    expect(ScheduledTrip::query()->orderBy('trip_date')->first()->seat_price_piastres)
        ->toBe($price['seatPricePiastres']);
});

it('cannot quote a car nobody has reviewed', function () {
    $token = approvedDriver();
    $id = readyCommute($token, Vehicle::sole()->id);

    Vehicle::sole()->forceFill(['approved_seats' => null])->save();

    test()->withToken($token)->getJson("/api/v1/commutes/{$id}/price")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'VEHICLE_SEATS_NOT_APPROVED');
});

/*
|--------------------------------------------------------------------------
| Who sees what (P-3, PAY-39)
|--------------------------------------------------------------------------
*/

it('shows the passenger the seat price only, and the driver his share too', function () {
    $driverToken = approvedDriver();
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $trip = ScheduledTrip::query()->orderBy('trip_date')->first();
    $paxToken = verifiedPassenger('01112223344');

    $bookingId = approveSeat($driverToken, requestSeat($paxToken, $commuteId, [
        'scheduledTripId' => $trip->id,
    ])->assertStatus(201)->json('data.id'));

    $asPassenger = test()->withToken($paxToken)->getJson("/api/v1/bookings/{$bookingId}")->assertOk();

    expect($asPassenger->json('data.price'))->toBe([
        'seatPricePiastres' => $trip->seat_price_piastres,
        'totalPiastres' => $trip->seat_price_piastres,
    ])
        ->and($asPassenger->json('data.trip.seatPricePiastres'))->toBe($trip->seat_price_piastres)
        ->and($asPassenger->json('data.trip'))->not->toHaveKey('driverSharePiastres');

    // 🔒 Nothing about RAFEEQ's cut anywhere in her payload.
    expect($asPassenger->getContent())
        ->not->toContain('platformFee')
        ->not->toContain('driverAmount')
        ->not->toContain('driverShare')
        ->not->toContain('commission');

    $asDriver = test()->withToken($driverToken)->getJson("/api/v1/bookings/{$bookingId}")->assertOk();

    expect($asDriver->json('data.price.seatPricePiastres'))->toBe($trip->seat_price_piastres)
        ->and($asDriver->json('data.price.driverSharePiastres'))->toBe($trip->driver_share_piastres)
        ->and($asDriver->json('data.trip.driverSharePiastres'))->toBe($trip->driver_share_piastres)
        ->and($asDriver->getContent())->not->toContain('commission');
});

/*
|--------------------------------------------------------------------------
| Cash only (P-4)
|--------------------------------------------------------------------------
*/

it('refuses an online payment while the beta is cash only', function () {
    $driverToken = approvedDriver();
    $commuteId = readyCommute($driverToken, Vehicle::sole()->id);
    test()->withToken($driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $paxToken = verifiedPassenger('01112223344');

    requestSeat($paxToken, $commuteId, [
        'scheduledTripId' => ScheduledTrip::query()->orderBy('trip_date')->first()->id,
        'paymentType' => 'online',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'PAYMENT_TYPE_NOT_AVAILABLE');
});
