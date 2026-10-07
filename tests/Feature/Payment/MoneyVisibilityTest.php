<?php

use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Payment\Support\PaymentSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The money, as the two people in the car see it (Phase 8, Bible §8).
 *
 * 🔴 What these mostly prove is who may see WHICH numbers. A shared commute is a group of people
 * who may be on different prices after a change, so a statement that showed everybody everything
 * would turn a lift to work into a negotiation — and a driver's fee debt is her income, which no
 * passenger has any business reading.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $this->paxToken = verifiedPassenger('01112223344');

    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));

    $this->groupId = CommuteGroup::sole()->id;
});

/*
|--------------------------------------------------------------------------
| The driver's balance
|--------------------------------------------------------------------------
*/

it('answers zeros for a driver who has never been owed anything', function () {
    // Not a 404: a driver with no cash trips yet has no balance ROW, and "no such driver" is the
    // wrong answer to "what do I owe".
    $balance = test()->withToken($this->driverToken)->getJson('/api/v1/driver/balance')
        ->assertOk()->json('data');

    expect($balance['outstandingFeePiastres'])->toBe(0)
        ->and($balance['lifetimeEarningsPiastres'])->toBe(0)
        ->and($balance['isBlockedFromPublishing'])->toBeFalse()
        ->and($balance['debtCapPiastres'])->toBe(PaymentSettings::maxDriverDebtPiastres());
});

it('shows the debt, the cap and the headroom together', function () {
    setDriverBalance(driverUserId(), ['outstanding_fee_piastres' => 5000, 'lifetime_earnings_piastres' => 160000]);

    $balance = test()->withToken($this->driverToken)->getJson('/api/v1/driver/balance')
        ->assertOk()->json('data');

    $cap = PaymentSettings::maxDriverDebtPiastres();

    expect($balance['outstandingFeePiastres'])->toBe(5000)
        ->and($balance['lifetimeEarningsPiastres'])->toBe(160000)
        // The headroom is stated, so a client does not hard-code the cap to draw a progress bar —
        // the cap is a runtime setting and a hard-coded one is wrong the first time staff move it.
        ->and($balance['remainingBeforeBlockPiastres'])->toBe($cap - 5000);
});

/**
 * 🔴 Computed from the CURRENT cap, not read from `is_blocked_from_publishing`.
 *
 * That column is a projection a nightly job maintains; the cap is a setting staff can change at any
 * moment. Trusting the flag would tell a driver she may publish minutes after the limit was lowered
 * below her balance, and the refusal would arrive as a surprise she had just been promised.
 */
it('follows the live cap rather than the stored flag', function () {
    setDriverBalance(driverUserId(), [
        'outstanding_fee_piastres' => 25000,
        // Deliberately stale and deliberately wrong.
        'is_blocked_from_publishing' => false,
    ]);

    expect(test()->withToken($this->driverToken)->getJson('/api/v1/driver/balance')
        ->assertOk()->json('data.isBlockedFromPublishing'))->toBeTrue();
});

/**
 * 🔒 Being over the cap stops her publishing something NEW. Every run already booked goes ahead,
 * because the passengers on it did nothing wrong — and a screen reading "account blocked" would be
 * both frightening and untrue.
 */
it('says that existing runs continue', function () {
    expect(test()->withToken($this->driverToken)->getJson('/api/v1/driver/balance')
        ->assertOk()->json('data.existingRunsContinue'))->toBeTrue();
});

it('never shows one person balance to another', function () {
    setDriverBalance(driverUserId(), ['outstanding_fee_piastres' => 9999]);

    // A passenger asking the same endpoint gets her OWN balance — zeros — not the driver's.
    $asPassenger = test()->withToken($this->paxToken)->getJson('/api/v1/driver/balance')
        ->assertOk();

    expect($asPassenger->json('data.outstandingFeePiastres'))->toBe(0)
        ->and($asPassenger->getContent())->not->toContain('9999');
});

it('requires a signed-in account', function () {
    test()->withoutToken()->getJson('/api/v1/driver/balance')->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| The group's week
|--------------------------------------------------------------------------
*/

it('shows the driver every passenger row with the fee broken out', function () {
    $statement = test()->withToken($this->driverToken)
        ->getJson("/api/v1/groups/{$this->groupId}/statement")
        ->assertOk()->json('data');

    expect($statement['asDriver'])->toBeTrue()
        ->and($statement['rows'])->toHaveCount(1);

    $row = $statement['rows'][0];

    expect($row['pricePiastres'])->toBe(8000)
        // 3% of the seat, deducted from her — the decision settled 2026-10-06.
        ->and($row['platformFeePiastres'])->toBe(240)
        ->and($row['driverKeepsPiastres'])->toBe(7760)
        // She is the one collecting the cash, so she needs to know from whom.
        ->and($row['passengerFirstName'])->toBe('سارة');

    expect($statement['totals']['duePiastres'])->toBe(8000)
        ->and($statement['totals']['platformFeePiastres'])->toBe(240)
        ->and($statement['totals']['driverKeepsPiastres'])->toBe(7760)
        // Stated rather than left as subtraction: this is the number the screen is opened to find.
        ->and($statement['totals']['outstandingPiastres'])->toBe(8000);
});

/**
 * 🔒 The central privacy property of this screen. Fellow passengers may be on different prices
 * after a change, and a statement that exposed that turns a lift to work into a negotiation.
 */
it('shows a passenger only her own rows, and none of the driver economics', function () {
    // A second passenger on the same group, at a price the first must not learn.
    fakeOtpSender();
    $other = verifiedPassenger('01223339999', device: 'pax-2');

    $otherBooking = approveSeat($this->driverToken, requestSeat($other, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));

    // Through the helper: `bookings` has a CHECK that price = fee + driver_amount, so moving one
    // column alone is refused by the database.
    repriceBooking($otherBooking, 12345);

    $statement = test()->withToken($this->paxToken)
        ->getJson("/api/v1/groups/{$this->groupId}/statement")
        ->assertOk();

    expect($statement->json('data.asDriver'))->toBeFalse()
        ->and($statement->json('data.rows'))->toHaveCount(1)
        ->and($statement->json('data.rows.0.bookingId'))->toBe($this->bookingId);

    // Not the other passenger's price, and not her name.
    expect($statement->getContent())
        ->not->toContain('12345')
        ->not->toContain('01223339999');

    // 🔒 And none of the driver's economics: the fee is out of HER share, not the passenger's
    // business.
    $row = $statement->json('data.rows.0');

    expect($row['platformFeePiastres'])->toBeNull()
        ->and($row['driverKeepsPiastres'])->toBeNull()
        ->and($row['passengerFirstName'])->toBeNull()
        ->and($statement->json('data.totals.platformFeePiastres'))->toBeNull()
        ->and($statement->json('data.totals.driverKeepsPiastres'))->toBeNull();
});

/**
 * 🔴 Read from the booking's snapshot columns, never recomputed from the commute's current price.
 * That is the whole reason those columns exist: a driver who raises her price must not retroactively
 * change what somebody already agreed to pay.
 */
it('reports what was agreed, not what the commute costs today', function () {
    test()->withToken($this->driverToken)->patchJson("/api/v1/commutes/{$this->commuteId}", [
        'pricePerSeatPiastres' => 11000,
    ])->assertOk();

    expect(test()->withToken($this->driverToken)->getJson("/api/v1/groups/{$this->groupId}/statement")
        ->assertOk()->json('data.rows.0.pricePiastres'))->toBe(8000);
});

it('separates what is due from what has been paid', function () {
    Booking::query()->whereKey($this->bookingId)
        ->update(['payment_status' => PaymentStatus::Paid->value]);

    $totals = test()->withToken($this->driverToken)
        ->getJson("/api/v1/groups/{$this->groupId}/statement")
        ->assertOk()->json('data.totals');

    expect($totals['duePiastres'])->toBe(8000)
        ->and($totals['paidPiastres'])->toBe(8000)
        ->and($totals['outstandingPiastres'])->toBe(0);
});

/**
 * Per decision D18 a no-show still owes: the seat was held and the car went. Flagged rather than
 * hidden, so the screen can show it differently from an ordinary ride.
 */
it('still charges a no-show, and says that is what it was', function () {
    Booking::query()->whereKey($this->bookingId)->update(['status' => 'no_show']);

    $statement = test()->withToken($this->driverToken)
        ->getJson("/api/v1/groups/{$this->groupId}/statement")
        ->assertOk()->json('data');

    expect($statement['rows'])->toHaveCount(1)
        ->and($statement['rows'][0]['wasNoShow'])->toBeTrue()
        ->and($statement['totals']['duePiastres'])->toBe(8000);
});

it('leaves out bookings that never happened', function (string $status) {
    Booking::query()->whereKey($this->bookingId)->update(['status' => $status]);

    $statement = test()->withToken($this->driverToken)
        ->getJson("/api/v1/groups/{$this->groupId}/statement")
        ->assertOk()->json('data');

    // Cancelled is not money; it is something that did not happen.
    expect($statement['rows'])->toBe([])
        ->and($statement['totals']['duePiastres'])->toBe(0);
})->with(['cancelled_by_passenger', 'cancelled_by_driver', 'expired']);

/**
 * 🔴 Saturday, not Monday. The platform's day bitmask starts at Saturday and the reference commute
 * runs Sunday to Thursday, so a Monday-start week would split every working week across two
 * statements and make both sets of totals meaningless.
 */
it('runs the week from Saturday to Friday', function () {
    $statement = test()->withToken($this->driverToken)
        ->getJson("/api/v1/groups/{$this->groupId}/statement")
        ->assertOk()->json('data');

    expect(Carbon::parse($statement['weekStart'])->dayOfWeekIso)->toBe(6)
        ->and(Carbon::parse($statement['weekEnd'])->dayOfWeekIso)->toBe(5);
});

it('reads a past week when asked', function () {
    $lastWeek = now()->subWeek();

    $statement = test()->withToken($this->driverToken)
        ->getJson("/api/v1/groups/{$this->groupId}/statement?weekOf=".$lastWeek->toDateString())
        ->assertOk()->json('data');

    // A different week, and the reference booking is not in it.
    expect($statement['weekStart'])->not->toBe(now()->startOfWeek(CarbonImmutable::SATURDAY)->toDateString())
        ->and($statement['rows'])->toBe([]);
});

/**
 * 🔒 404 rather than 403, and for a group that belongs to strangers as much as for one that does not
 * exist: whether a particular group exists is not something somebody may learn by asking for its
 * money.
 */
it('refuses a group the caller is not in', function () {
    fakeOtpSender();
    $stranger = verifiedPassenger('01223339999', device: 'pax-2');

    test()->withToken($stranger)->getJson("/api/v1/groups/{$this->groupId}/statement")
        ->assertStatus(404);

    test()->withToken($this->paxToken)->getJson('/api/v1/groups/'.str_repeat('0', 26).'/statement')
        ->assertStatus(404);
});

/**
 * The driver's user id, which IS the driver profile's primary key (the profile is 1:1 and keyed by
 * it).
 */
function driverUserId(): string
{
    return DriverProfile::sole()->user_id;
}
