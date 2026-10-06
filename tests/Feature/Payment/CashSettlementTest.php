<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DriverFeeLedgerType;
use App\Domains\Payment\Enums\PaymentTransactionStatus;
use App\Domains\Payment\Models\DriverBalance;
use App\Domains\Payment\Models\DriverFeeLedger;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Support\DriverFeeLedgerWriter;
use App\Domains\Trip\Models\Attendance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 8, the cash path (Bible §8.1): the passenger paid in the car, and the platform records it
 * once the driver has confirmed who travelled and the safeguards have had their time.
 *
 * 🔴 What is guarded is when money is NOT recorded as much as when it is: never before the delay,
 * never on a disputed record, never on a no-show, and never twice.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;
    $this->paxToken = verifiedPassenger('01112223344');
    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->json('data.id'));

    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
});

/**
 * The driver says the passenger was in the car, and the clock is moved back past the delay — on the
 * row, not the clock: travelling hours would expire the test's access token.
 */
function travelledHoursAgo(int $hours = 3): void
{
    underway(test()->driverToken, test()->tripId);
    checkIn(test()->driverToken, test()->tripId, ['bookingId' => test()->bookingId])->assertOk();

    Attendance::query()->whereKey(test()->bookingId)->update(['confirmed_at' => now()->subHours($hours)]);
}

it('records the cash, the fee as the driver’s debt, and the booking as paid', function () {
    travelledHoursAgo();

    $this->artisan('payments:settle-cash')->expectsOutputToContain('Settled 1')->assertSuccessful();

    $booking = Booking::query()->findOrFail($this->bookingId);
    $payment = Payment::query()->where('booking_id', $this->bookingId)->sole();
    $ledger = DriverFeeLedger::query()->where('booking_id', $this->bookingId)->sole();
    $balance = DriverBalance::query()->findOrFail($this->driver->id);

    expect($booking->payment_status)->toBe(PaymentStatus::Paid)
        ->and($payment->status)->toBe(PaymentTransactionStatus::SettledOffline)
        ->and($payment->amount_piastres)->toBe($booking->price_snapshot_piastres)
        ->and($payment->platform_fee_piastres + $payment->driver_amount_piastres)->toBe($payment->amount_piastres)
        ->and($payment->idempotency_key)->toBe("bk_{$this->bookingId}_charge")
        ->and($ledger->type)->toBe(DriverFeeLedgerType::FeeDue)
        ->and($ledger->amount_piastres)->toBe($booking->platform_fee_snapshot_piastres)
        ->and($ledger->balance_after_piastres)->toBe($booking->platform_fee_snapshot_piastres)
        ->and($balance->outstanding_fee_piastres)->toBe($booking->platform_fee_snapshot_piastres)
        ->and($balance->lifetime_earnings_piastres)->toBe($booking->driver_amount_snapshot_piastres);
});

it('waits out the collection delay', function () {
    travelledHoursAgo(hours: 1);

    $this->artisan('payments:settle-cash')->expectsOutputToContain('Settled 0')->assertSuccessful();

    expect(Payment::query()->exists())->toBeFalse()
        ->and(Booking::query()->findOrFail($this->bookingId)->payment_status)->toBe(PaymentStatus::NotDue);
});

it('settles once, however many times it runs', function () {
    travelledHoursAgo();

    $this->artisan('payments:settle-cash')->assertSuccessful();
    $this->artisan('payments:settle-cash')->expectsOutputToContain('Settled 0')->assertSuccessful();

    expect(Payment::query()->count())->toBe(1)
        ->and(DriverFeeLedger::query()->count())->toBe(1);
});

/**
 * 🔴 A passenger who says "I was not in that car" stops the collection until staff decide.
 */
it('collects nothing on a disputed record, and marks the booking disputed', function () {
    travelledHoursAgo(hours: 1);

    $this->withToken($this->paxToken)->postJson("/api/v1/bookings/{$this->bookingId}/dispute", [
        'reason' => 'العربية مشيت قبل ما أوصل.',
    ])->assertOk();

    Attendance::query()->whereKey($this->bookingId)->update(['confirmed_at' => now()->subHours(3)]);

    $this->artisan('payments:settle-cash')->expectsOutputToContain('Settled 0')->assertSuccessful();

    expect(Booking::query()->findOrFail($this->bookingId)->payment_status)->toBe(PaymentStatus::Disputed)
        ->and(Payment::query()->exists())->toBeFalse();
});

it('never collects on a no-show — that policy is not decided', function () {
    underway($this->driverToken, $this->tripId);
    $this->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])->assertOk();
    Attendance::query()->whereKey($this->bookingId)->update(['confirmed_at' => now()->subHours(3)]);

    $this->artisan('payments:settle-cash')->expectsOutputToContain('Settled 0')->assertSuccessful();

    expect(Payment::query()->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The debt cap
|--------------------------------------------------------------------------
*/

it('stops a driver over the debt cap from publishing, and lets them once it is settled', function () {
    DriverFeeLedgerWriter::record($this->driver->id, DriverFeeLedgerType::FeeDue, 25_000);

    $draft = createCommute($this->driverToken, Vehicle::sole()->id)->json('data.id');
    saveRoute($this->driverToken, $draft);
    saveSchedule($this->driverToken, $draft);

    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$draft}/publish")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'DRIVER_DEBT_LIMIT_REACHED')
        ->assertJsonPath('error.fields.outstandingPiastres.0', '25000')
        ->assertJsonPath('error.fields.capPiastres.0', '20000');

    expect(DriverBalance::query()->findOrFail($this->driver->id)->is_blocked_from_publishing)->toBeTrue();

    DriverFeeLedgerWriter::record($this->driver->id, DriverFeeLedgerType::FeeSettled, -25_000, note: 'Paid by InstaPay');

    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$draft}/publish")->assertOk();

    expect(DriverBalance::query()->findOrFail($this->driver->id)->is_blocked_from_publishing)->toBeFalse();
});

/**
 * Existing bookings continue (Bible §8.1): the passengers on a run did nothing wrong.
 */
it('does not stop a driver over the cap from driving the runs already booked', function () {
    DriverFeeLedgerWriter::record($this->driver->id, DriverFeeLedgerType::FeeDue, 25_000);

    runLeavingIn($this->tripId, 10);
    $this->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/start")->assertStatus(201);
});

it('applies a new cap at once, for everybody', function () {
    DriverFeeLedgerWriter::record($this->driver->id, DriverFeeLedgerType::FeeDue, 10_000);
    PlatformSetting::updateOrCreate(['setting_key' => 'payment.max_driver_debt_piastres'], ['setting_value' => 5_000, 'value_type' => 'integer']);

    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertOk();
    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/resume")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'DRIVER_DEBT_LIMIT_REACHED');
});

/*
|--------------------------------------------------------------------------
| The ledger is the truth
|--------------------------------------------------------------------------
*/

it('never takes a balance below zero', function () {
    DriverFeeLedgerWriter::record($this->driver->id, DriverFeeLedgerType::FeeDue, 300);
    $entry = DriverFeeLedgerWriter::record($this->driver->id, DriverFeeLedgerType::FeeSettled, -1_000);

    expect($entry->amount_piastres)->toBe(-300)
        ->and($entry->balance_after_piastres)->toBe(0)
        ->and(DriverBalance::query()->findOrFail($this->driver->id)->outstanding_fee_piastres)->toBe(0);
});

it('finds a balance that has drifted from its ledger, and does not repair it', function () {
    DriverFeeLedgerWriter::record($this->driver->id, DriverFeeLedgerType::FeeDue, 300);
    DriverBalance::query()->whereKey($this->driver->id)->update(['outstanding_fee_piastres' => 999]);

    Log::shouldReceive('critical')->once();

    $this->artisan('payments:reconcile-balances')->assertFailed();

    expect(DriverBalance::query()->findOrFail($this->driver->id)->outstanding_fee_piastres)->toBe(999);
});

it('stamps a balance that matches its ledger', function () {
    DriverFeeLedgerWriter::record($this->driver->id, DriverFeeLedgerType::FeeDue, 300);

    $this->artisan('payments:reconcile-balances')->assertSuccessful();

    expect(DriverBalance::query()->findOrFail($this->driver->id)->reconciled_at)->not->toBeNull();
});
