<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use Illuminate\Support\Facades\Storage;

/**
 * F1-4 (PROF-31, D-0028): deleting an account — refused while she owes money or has a trip inside
 * the fee window; otherwise her trips are cancelled for free, every session ends, she is anonymised.
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

    $this->passenger = User::query()->where('phone_e164', '+201112223344')->sole();
    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
});

it('says she may delete when she owes nothing and no trip is close', function () {
    runLeavingIn($this->tripId, 48 * 60, dayOffset: -30);

    $this->withToken($this->paxToken)->getJson('/api/v1/account/deletion')
        ->assertOk()
        ->assertJsonPath('data.canDelete', true)
        ->assertJsonPath('data.debtPiastres', 0)
        ->assertJsonPath('data.tripsInFeeWindow', []);
});

it('refuses while she owes money, and says how much', function () {
    runLeavingIn($this->tripId, 48 * 60, dayOffset: -30);
    owe($this->passenger->id, 4500, side: DebtSide::Passenger, type: DebtEntryType::Fee);

    $this->withToken($this->paxToken)->getJson('/api/v1/account/deletion')
        ->assertJsonPath('data.canDelete', false)
        ->assertJsonPath('data.debtPiastres', 4500);

    $this->withToken($this->paxToken)->deleteJson('/api/v1/account')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ACCOUNT_HAS_DEBT')
        ->assertJsonPath('error.fields.debtPiastres.0', '4500');

    expect(User::query()->find($this->passenger->id))->not->toBeNull();
});

it('refuses a passenger whose seat is inside the late-cancel window', function () {
    runLeavingIn($this->tripId, 6 * 60);

    $this->withToken($this->paxToken)->deleteJson('/api/v1/account')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ACCOUNT_HAS_TRIP_IN_FEE_WINDOW')
        ->assertJsonPath('error.fields.bookingIds.0', $this->bookingId);
});

it('refuses a driver with a booked day under 16 hours away', function () {
    runLeavingIn($this->tripId, 14 * 60);

    $this->withToken($this->driverToken)->getJson('/api/v1/account/deletion')
        ->assertJsonPath('data.canDelete', false)
        ->assertJsonPath('data.tripsInFeeWindow.0.role', 'driver');

    $this->withToken($this->driverToken)->deleteJson('/api/v1/account')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ACCOUNT_HAS_TRIP_IN_FEE_WINDOW');
});

it('deletes a passenger: her seat is cancelled for free and she is anonymised', function () {
    runLeavingIn($this->tripId, 48 * 60, dayOffset: -30);

    $this->withToken($this->paxToken)->deleteJson('/api/v1/account')
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    $row = User::withTrashed()->findOrFail($this->passenger->id);

    expect($row->trashed())->toBeTrue()
        ->and($row->account_status)->toBe(AccountStatus::Deleted)
        ->and($row->full_name)->toBe('deleted')
        ->and($row->phone_e164)->toStartWith('deleted_')
        ->and(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::CancelledByPassenger)
        // Nothing charged to anybody.
        ->and(DebtEntry::query()->where('type', DebtEntryType::Fee->value)->count())->toBe(0);
});

it('ends every session, so the old token stops working', function () {
    runLeavingIn($this->tripId, 48 * 60, dayOffset: -30);

    $this->withToken($this->paxToken)->deleteJson('/api/v1/account')->assertOk();

    app('auth')->forgetGuards();

    $this->withToken($this->paxToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('deletes a driver: the commute is archived and the rider is told, with no fee', function () {
    runLeavingIn($this->tripId, 48 * 60, dayOffset: -30);

    $this->withToken($this->driverToken)->deleteJson('/api/v1/account')->assertOk();

    expect(CommuteOffer::query()->findOrFail($this->commuteId)->status)->toBe(CommuteOfferStatus::Archived)
        ->and(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::CancelledByDriver)
        ->and(DebtEntry::query()->where('side', DebtSide::Driver->value)->where('type', DebtEntryType::Fee->value)->count())->toBe(0);
});

it('lets the same number sign up again afterwards', function () {
    runLeavingIn($this->tripId, 48 * 60, dayOffset: -30);

    $this->withToken($this->paxToken)->deleteJson('/api/v1/account')->assertOk();

    app('auth')->forgetGuards();
    $this->withoutToken();

    signIn('01112223344', 'pax-again');

    expect(User::query()->where('phone_e164', '+201112223344')->count())->toBe(1)
        ->and(User::query()->where('phone_e164', '+201112223344')->sole()->id)->not->toBe($this->passenger->id);
});

/*
|--------------------------------------------------------------------------
| The web page (Google Play)
|--------------------------------------------------------------------------
*/

it('deletes from the web page with nothing but the phone and a code', function () {
    runLeavingIn($this->tripId, 48 * 60, dayOffset: -30);
    $sender = fakeOtpSender();
    $this->withoutToken();

    $this->get('/delete-account')->assertOk()->assertSee('امسح حسابك');

    $this->post('/delete-account/code', ['phone' => '01112223344'])->assertOk()->assertSee('اكتب الكود');

    $this->post('/delete-account/confirm', ['code' => $sender->lastCode()])
        ->assertOk()
        ->assertSee('حسابك اتمسح');

    expect(User::query()->find($this->passenger->id))->toBeNull();
});

it('shows the reason on the web page when deletion is refused', function () {
    runLeavingIn($this->tripId, 6 * 60);
    $sender = fakeOtpSender();
    $this->withoutToken();

    $this->post('/delete-account/code', ['phone' => '01112223344']);

    $this->post('/delete-account/confirm', ['code' => $sender->lastCode()])
        ->assertOk()
        ->assertSee(__('errors.ACCOUNT_HAS_TRIP_IN_FEE_WINDOW'));
});

/**
 * 🔒 The web page texts a `high_risk_action` code. A sign-in code must not delete anybody, and the
 * page must never create an account for a number that has none.
 */
it('creates no account for a number that has none', function () {
    $sender = fakeOtpSender();
    $this->withoutToken();

    $this->post('/delete-account/code', ['phone' => '01223334455']);

    $this->post('/delete-account/confirm', ['code' => $sender->lastCode()])
        ->assertOk()
        ->assertSee('مفيش حساب');

    expect(User::query()->where('phone_e164', '+201223334455')->exists())->toBeFalse();
});
