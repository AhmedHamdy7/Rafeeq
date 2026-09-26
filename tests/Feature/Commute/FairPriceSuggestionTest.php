<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Commute\Support\FairPriceSuggestion;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\ValueObjects\Distance;
use Illuminate\Support\Facades\Storage;

/**
 * "The fair suggested price" on the publish screen (ERD §23.3).
 *
 * 🔴 Why the platform says a number at all: a commute is shared COST, not a fare. A driver
 * with no reference either undercharges and quietly subsidises strangers, or overcharges and
 * turns their own car into an unlicensed taxi — and only one of those two is their problem
 * to notice.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
});

it('computes the suggestion exactly as the ERD sets out', function () {
    // 27 km × 750 piastres = 20,250 for the run, ÷ 3 assumed passengers = 6,750,
    // rounded to the nearest 500 = 7,000 (70 EGP).
    $suggestion = FairPriceSuggestion::forDistance(Distance::fromKilometres(27));

    expect($suggestion->runCostPiastres)->toBe(20250)
        ->and($suggestion->assumedOccupancy)->toBe(3)
        ->and($suggestion->suggestedPiastres)->toBe(7000);
});

it('rounds to a number somebody would actually type', function () {
    // 6,750 ÷ 3 would be 2,250 — the step pulls it to 2,500, not 2,247.
    $suggestion = FairPriceSuggestion::forDistance(Distance::fromKilometres(9));

    expect($suggestion->suggestedPiastres % 500)->toBe(0);
});

/**
 * Clamped last, because suggesting a price the driver is not allowed to set would be advice
 * that fails validation.
 */
it('never suggests a price outside what may be set', function () {
    $tiny = FairPriceSuggestion::forDistance(Distance::fromKilometres(1));
    $enormous = FairPriceSuggestion::forDistance(Distance::fromKilometres(500));

    expect($tiny->suggestedPiastres)->toBe((int) config('rafeeq.commute.min_price_piastres'))
        ->and($enormous->suggestedPiastres)->toBe((int) config('rafeeq.commute.max_price_piastres'));
});

/**
 * 🔴 `cost_per_km_piastres` tracks the fuel price, so it moves on somebody else's schedule.
 * A suggestion built on a hard-coded fuel cost would quietly advise people to undercharge
 * the week petrol goes up.
 */
it('follows the fuel index from settings, not from code', function () {
    $before = FairPriceSuggestion::forDistance(Distance::fromKilometres(27))->suggestedPiastres;

    PlatformSetting::query()->create([
        'setting_key' => 'pricing.cost_per_km_piastres',
        'setting_value' => 1500,
        'value_type' => 'integer',
        'description' => 'Fuel index doubled',
    ]);

    $after = FairPriceSuggestion::forDistance(Distance::fromKilometres(27))->suggestedPiastres;

    expect($after)->toBeGreaterThan($before);
});

it('serves the suggestion with the bounds the slider needs', function () {
    $data = test()->withToken($this->driverToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/price-suggestion")
        ->assertOk()->json('data');

    expect($data['suggestedPiastres'])->toBeGreaterThanOrEqual($data['minPiastres'])
        ->and($data['suggestedPiastres'])->toBeLessThanOrEqual($data['maxPiastres'])
        // Screen 24 shows the reasoning beside the number, not just the number.
        ->and($data['runCostPiastres'])->toBeGreaterThan(0)
        ->and($data['assumedOccupancy'])->toBe(3)
        ->and($data['distanceKm'])->toBeGreaterThan(0);
});

it('refuses to price a commute with no route yet', function () {
    $draftId = createCommute($this->driverToken, Vehicle::sole()->id)
        ->assertStatus(201)->json('data.id');

    test()->withToken($this->driverToken)
        ->getJson("/api/v1/commutes/{$draftId}/price-suggestion")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_INCOMPLETE');
});

/**
 * 🔒 A commute's length is its driver's business. A 404 rather than a 403, because a 403
 * would confirm the id exists.
 */
it('hides the suggestion for somebody else commute', function () {
    fakeOtpSender();
    $otherDriver = approvedDriver(phone: '01223339999', devicePublicId: 'driver-2', seed: 2);

    test()->withToken($otherDriver)
        ->getJson("/api/v1/commutes/{$this->commuteId}/price-suggestion")
        ->assertStatus(404);
});

/**
 * The bounds are a legal boundary, not a preference: a commute is shared cost, and a price
 * far above the cost of driving makes the platform an unlicensed taxi service.
 */
it('holds the shared-cost bounds the screen slider shows', function () {
    expect((int) config('rafeeq.commute.min_price_piastres'))->toBe(5000)
        ->and((int) config('rafeeq.commute.max_price_piastres'))->toBe(12000);

    // And a price outside them is refused at the door.
    createCommute($this->driverToken, Vehicle::sole()->id, ['pricePerSeatPiastres' => 50000])
        ->assertStatus(422);

    createCommute($this->driverToken, Vehicle::sole()->id, ['pricePerSeatPiastres' => 500])
        ->assertStatus(422);
});
