<?php

use App\Domains\Admin\Actions\ManageDebtAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Enums\PaymentTransactionStatus;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Trip\Models\Attendance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The cash path and the driver's commission (PAY-07 → PAY-15; Feature 03 backend file L-1 → L-4).
 *
 * 🔴 The seat is PAID the moment she is dropped off, and the commission lands on the driver's debt
 * at once. There is no amount cap any more — only the Friday deadline: owing a lot on Wednesday is
 * fine, owing anything from last week on Saturday is not.
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
 * She got in, and the run finished with her in the car.
 */
function rodeAndFinished(): void
{
    underway(test()->driverToken, test()->tripId);
    checkIn(test()->driverToken, test()->tripId, ['bookingId' => test()->bookingId])->assertOk();

    test()->withToken(test()->driverToken)
        ->postJson('/api/v1/trips/'.test()->tripId.'/status', ['status' => 'IN_PROGRESS'])->assertOk();
    test()->withToken(test()->driverToken)->postJson('/api/v1/trips/'.test()->tripId.'/complete')->assertOk();
}

it('records the cash, the commission as the driver’s debt, and the booking as paid', function () {
    rodeAndFinished();

    $booking = Booking::query()->findOrFail($this->bookingId);
    $payment = Payment::query()->where('booking_id', $this->bookingId)->sole();
    $line = DebtEntry::query()->where('booking_id', $this->bookingId)->sole();

    expect($booking->payment_status)->toBe(PaymentStatus::Paid)
        ->and($payment->status)->toBe(PaymentTransactionStatus::SettledOffline)
        ->and($payment->amount_piastres)->toBe($booking->price_snapshot_piastres)
        ->and($payment->platform_fee_piastres + $payment->driver_amount_piastres)->toBe($payment->amount_piastres)
        ->and($line->type)->toBe(DebtEntryType::Commission)
        ->and($line->side)->toBe(DebtSide::Driver)
        ->and($line->user_id)->toBe($this->driver->id)
        ->and($line->amount_piastres)->toBe($booking->platform_fee_snapshot_piastres)
        ->and(DebtLedger::owedBy($this->driver, DebtSide::Driver))->toBe($booking->platform_fee_snapshot_piastres);
});

it('settles a ride the drop-off missed, once, however many times the sweep runs', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();
    // The journey ended for her, but nothing settled it.
    Attendance::query()->whereKey($this->bookingId)->update(['checked_out_at' => now()]);

    $this->artisan('payments:settle-cash')->expectsOutputToContain('Settled 1')->assertSuccessful();
    $this->artisan('payments:settle-cash')->expectsOutputToContain('Settled 0')->assertSuccessful();

    expect(Payment::query()->count())->toBe(1)
        ->and(DebtEntry::query()->where('type', DebtEntryType::Commission->value)->count())->toBe(1);
});

it('never settles a no-show as a fare', function () {
    underway($this->driverToken, $this->tripId);
    arrivedAndWaited($this->driverToken, $this->tripId, $this->bookingId);
    $this->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $this->bookingId])->assertOk();

    $this->artisan('payments:settle-cash')->expectsOutputToContain('Settled 0')->assertSuccessful();

    expect(Payment::query()->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The Friday deadline (PAY-11, PAY-12, PAY-15)
|--------------------------------------------------------------------------
*/

it('lets a driver owe any amount this week and still publish', function () {
    DebtLedger::charge($this->driver, DebtEntryType::Commission, 500_000, 'seat_commission', side: DebtSide::Driver);

    $draft = createCommute($this->driverToken, Vehicle::sole()->id)->json('data.id');
    saveRoute($this->driverToken, $draft);
    saveSchedule($this->driverToken, $draft, ['promisedArrivalTime' => '18:00:00']);

    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$draft}/publish")->assertOk();
});

it('stops a driver with last week unpaid from publishing, and lets her once staff confirm the payment', function () {
    owe($this->driver->id, 1_800, weeksAgo: 1);

    $draft = createCommute($this->driverToken, Vehicle::sole()->id)->json('data.id');
    saveRoute($this->driverToken, $draft);
    saveSchedule($this->driverToken, $draft, ['promisedArrivalTime' => '18:00:00']);

    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$draft}/publish")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'DRIVER_COMMISSION_OVERDUE')
        ->assertJsonPath('error.fields.amountOwedPiastres.0', '1800');

    $paymentId = $this->withToken($this->driverToken)->postJson('/api/v1/account/debt/payments', [
        'side' => 'driver',
        'amountPiastres' => 1_800,
        'instapayReference' => 'IP12345678',
    ])->assertStatus(201)
        ->assertJsonPath('data.status', 'pending_confirmation')
        ->json('data.id');

    // Nothing comes off until staff see the money.
    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$draft}/publish")->assertForbidden();

    app(ManageDebtAction::class)->confirmPayment(DebtPayment::query()->findOrFail($paymentId), AdminUser::factory()->create());

    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$draft}/publish")->assertOk();

    expect(DebtLedger::owedBy($this->driver, DebtSide::Driver))->toBe(0);
});

/**
 * PAY-12: the riders already booked did nothing wrong.
 */
it('does not stop an overdue driver from driving the runs already booked', function () {
    owe($this->driver->id, 1_800, weeksAgo: 1);

    runLeavingIn($this->tripId, 10);
    $this->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/start")->assertStatus(201);
});

it('refuses a payment when that side owes nothing', function () {
    $this->withToken($this->paxToken)->postJson('/api/v1/account/debt/payments', [
        'side' => 'passenger',
        'amountPiastres' => 1_000,
        'instapayReference' => 'IP12345678',
    ])->assertStatus(409)->assertJsonPath('error.code', 'DEBT_NOTHING_OWED');
});

/*
|--------------------------------------------------------------------------
| The ledger is the truth
|--------------------------------------------------------------------------
*/

it('keeps a credit beyond what she owes, below zero, for next time', function () {
    DebtLedger::charge($this->driver, DebtEntryType::Commission, 300, 'seat_commission', side: DebtSide::Driver);
    $entry = DebtLedger::credit($this->driver, DebtEntryType::Credit, 1_000, 'appeal_upheld', side: DebtSide::Driver);

    expect($entry->balance_after_piastres)->toBe(-700)
        ->and(DebtLedger::owedBy($this->driver, DebtSide::Driver))->toBe(0);
});

it('finds an account that has drifted from its ledger, and does not repair it', function () {
    DebtLedger::charge($this->driver, DebtEntryType::Commission, 300, 'seat_commission', side: DebtSide::Driver);
    DebtAccount::query()->where('user_id', $this->driver->id)->update(['balance_piastres' => 999]);

    Log::shouldReceive('critical')->once();

    $this->artisan('payments:reconcile-balances')->assertFailed();

    expect(DebtLedger::balanceOf($this->driver, DebtSide::Driver))->toBe(999);
});

it('stamps an account that matches its ledger', function () {
    DebtLedger::charge($this->driver, DebtEntryType::Commission, 300, 'seat_commission', side: DebtSide::Driver);

    $this->artisan('payments:reconcile-balances')->assertSuccessful();

    expect(DebtAccount::query()->where('user_id', $this->driver->id)->sole()->reconciled_at)->not->toBeNull();
});
