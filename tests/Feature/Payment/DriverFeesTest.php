<?php

use App\Domains\Admin\Actions\ResolveFeeAppealAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\FeeAppeal;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Trip\Actions\SweepDelayedTripsAction;
use Illuminate\Support\Facades\Storage;

/**
 * What a driver pays when he lets passengers down (PAY-23 → PAY-29; Feature 03 backend file C-5 →
 * C-10, F-5 → F-10).
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
    $this->price = Booking::query()->findOrFail($this->bookingId)->price_snapshot_piastres;
});

function driverFees(): int
{
    return (int) DebtEntry::query()
        ->where('side', DebtSide::Driver->value)
        ->where('type', DebtEntryType::Fee->value)
        ->sum('amount_piastres');
}

/*
|--------------------------------------------------------------------------
| PAY-23: cancelling before the trip, by notice
|--------------------------------------------------------------------------
*/

it('charges nothing for removing a passenger 16 hours or more ahead', function () {
    runLeavingIn($this->tripId, 20 * 60);

    $this->withToken($this->driverToken)
        ->postJson("/api/v1/driver/bookings/{$this->bookingId}/cancel", ['reason' => 'She refused the new route.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED_BY_DRIVER');

    expect(driverFees())->toBe(0);
});

it('charges 10% between 10 and 16 hours, and 25% under 10', function (int $hours, int $percent) {
    runLeavingIn($this->tripId, $hours * 60);

    $this->withToken($this->driverToken)
        ->postJson("/api/v1/driver/bookings/{$this->bookingId}/cancel", ['reason' => 'Changed my plans.'])
        ->assertOk();

    expect(driverFees())->toBe(intdiv($this->price * $percent, 100))
        ->and(Notification::query()->where('user_id', $this->driver->id)->where('type', 'driver_cancel_fee')->count())->toBe(1);
})->with([[12, 10], [5, 25]]);

it('lets only the driver remove a passenger through the driver route', function () {
    $this->withToken($this->paxToken)
        ->postJson("/api/v1/driver/bookings/{$this->bookingId}/cancel", ['reason' => 'not mine'])
        ->assertNotFound();
});

/**
 * C-9: the preview is the same arithmetic as the act.
 */
it('previews "Cancel today" with the fee it then charges', function () {
    runLeavingIn($this->tripId, 5 * 60);

    $preview = $this->withToken($this->driverToken)->getJson("/api/v1/trips/{$this->tripId}/cancel-preview")
        ->assertOk()->json('data');

    expect($preview['affectedSeats'])->toBe(1)
        ->and($preview['reason'])->toBe('short_notice')
        ->and($preview['perTrip'][0]['percent'])->toBe(25)
        ->and($preview['feePiastres'])->toBe(intdiv($this->price * 25, 100));

    $this->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/cancel")->assertOk();

    expect(driverFees())->toBe($preview['feePiastres']);
});

it('previews and charges a pause by notice, and nothing for an automatic one', function () {
    runLeavingIn($this->tripId, 12 * 60);

    $preview = $this->withToken($this->driverToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/cancel-preview?action=pause")
        ->assertOk()->json('data');

    expect($preview['reason'])->toBe('mid_notice')
        ->and($preview['feePiastres'])->toBe(intdiv($this->price * 10, 100));

    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/pause")->assertOk();

    expect(driverFees())->toBe($preview['feePiastres']);
});

it('charges a delete by notice', function () {
    runLeavingIn($this->tripId, 5 * 60);

    $this->withToken($this->driverToken)->deleteJson("/api/v1/commutes/{$this->commuteId}")->assertOk();

    expect(driverFees())->toBe(intdiv($this->price * 25, 100));
});

it('refuses a preview for an unknown act', function () {
    $this->withToken($this->driverToken)
        ->getJson("/api/v1/commutes/{$this->commuteId}/cancel-preview?action=explode")
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| PAY-24, PAY-25: ending a run that set off
|--------------------------------------------------------------------------
*/

it('charges the whole seat for ending a run, and the passenger never collected pays nothing', function () {
    underway($this->driverToken, $this->tripId);

    $this->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/cancel-active", ['reason' => 'other'])
        ->assertOk();

    expect(driverFees())->toBe($this->price)
        ->and(Booking::query()->findOrFail($this->bookingId)->payment_status)->not->toBe(PaymentStatus::Paid);
});

it('waives the fare of a passenger aboard when the run is ended for another reason', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    $this->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/cancel-active", ['reason' => 'other'])
        ->assertOk();

    // Never settled later by the cash sweep either.
    $this->artisan('payments:settle-cash')->expectsOutputToContain('Settled 0')->assertSuccessful();

    expect(Booking::query()->findOrFail($this->bookingId)->payment_status)->toBe(PaymentStatus::Waived);
});

/*
|--------------------------------------------------------------------------
| PAY-26, PAY-29: never came, or came late
|--------------------------------------------------------------------------
*/

it('charges half of every seat when the run expires, voiding the lateness fee', function () {
    // She gave up waiting: 25 % on the driver.
    runLeavingIn($this->tripId, -11);
    $this->withToken($this->paxToken)->postJson("/api/v1/bookings/{$this->bookingId}/exit-without-fee")->assertOk();

    expect(driverFees())->toBe(intdiv($this->price * 25, 100));

    // Then the run never started at all: the no-show fee replaces it.
    runLeavingIn($this->tripId, -25);
    app(SweepDelayedTripsAction::class)->sweep();

    expect(DebtLedger::owedBy($this->driver, DebtSide::Driver))->toBe(intdiv($this->price * 50, 100))
        ->and(DebtEntry::query()->where('reason_code', 'driver_no_show')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| SAFE-11, PAY-10: his appeal
|--------------------------------------------------------------------------
*/

it('credits the fee back when staff overturn his appeal', function () {
    runLeavingIn($this->tripId, 5 * 60);
    $this->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/cancel")->assertOk();

    $fee = DebtEntry::query()->where('reason_code', 'driver_cancel')->sole();

    $appealId = $this->withToken($this->driverToken)
        ->postJson("/api/v1/account/debt/entries/{$fee->id}/appeal", ['reason' => 'My child was taken to hospital; the report is attached.'])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'pending')
        ->json('data.id');

    $this->withToken($this->driverToken)
        ->postJson("/api/v1/account/debt/entries/{$fee->id}/appeal", ['reason' => 'Asking a second time.'])
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'ALREADY_APPEALED');

    app(ResolveFeeAppealAction::class)->driverFee(FeeAppeal::query()->findOrFail($appealId), AdminUser::factory()->create(), true, 'Hospital record checks out.');

    expect(DebtLedger::owedBy($this->driver, DebtSide::Driver))->toBe(0);
});

it('closes the appeal after 48 hours', function () {
    runLeavingIn($this->tripId, 5 * 60);
    $this->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/cancel")->assertOk();

    $fee = DebtEntry::query()->where('reason_code', 'driver_cancel')->sole();
    DebtEntry::query()->whereKey($fee->id)->update(['created_at' => now()->subHours(49)]);

    $this->withToken($this->driverToken)
        ->postJson("/api/v1/account/debt/entries/{$fee->id}/appeal", ['reason' => 'Late, but please look.'])
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'APPEAL_WINDOW_CLOSED');
});

/**
 * X-4: a block cancels the bookings between the two, charged to the blocker under the normal
 * rules — and the preview says what that will cost first.
 */
it('previews and charges a driver blocking his passenger by notice', function () {
    runLeavingIn($this->tripId, 5 * 60);
    $paxId = Booking::query()->findOrFail($this->bookingId)->passenger_user_id;

    $preview = $this->withToken($this->driverToken)
        ->getJson("/api/v1/safety/blocked-users/preview?userId={$paxId}")
        ->assertOk()->json('data');

    expect($preview['feePiastres'])->toBe(intdiv($this->price * 25, 100))
        ->and($preview['reason'])->toBe('driver_cancel');

    $this->withToken($this->driverToken)->postJson('/api/v1/safety/blocked-users', ['userId' => $paxId])->assertSuccessful();

    expect(driverFees())->toBe($preview['feePiastres']);
});
