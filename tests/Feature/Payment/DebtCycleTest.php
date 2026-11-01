<?php

use App\Domains\Admin\Actions\ManageDebtAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\SettlementWeek;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * The weekly debt cycle and suspension for debt (PAY-11 → PAY-17, PAY-34, PAY-45, PAY-46).
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
    $this->pax = User::query()->where('phone_e164', '+201112223344')->sole();
});

/*
|--------------------------------------------------------------------------
| PAY-13: the late fee
|--------------------------------------------------------------------------
*/

it('adds 10% once to last week’s unpaid commission, and tells her at once', function () {
    owe($this->driver->id, 1_800, weeksAgo: 1);

    $this->artisan('payments:sweep-debts')->assertSuccessful();
    $this->artisan('payments:sweep-debts')->assertSuccessful();

    $fees = DebtEntry::query()->where('type', DebtEntryType::LateFee->value)->get();

    expect($fees)->toHaveCount(1)
        ->and($fees->first()->amount_piastres)->toBe(180)
        // It belongs to the week it is for, so it is due at once: 180 → 198.
        ->and($fees->first()->week_of->toDateString())->toBe(SettlementWeek::shift(SettlementWeek::current(), -1))
        ->and(DebtLedger::owedBy($this->driver, DebtSide::Driver))->toBe(1_980)
        ->and(Notification::query()->where('user_id', $this->driver->id)->where('type', 'debt_late_fee_added')->exists())->toBeTrue();
});

it('adds no late fee to this week’s commission', function () {
    owe($this->driver->id, 1_800, weeksAgo: 0);

    $this->artisan('payments:sweep-debts')->assertSuccessful();

    expect(DebtEntry::query()->where('type', DebtEntryType::LateFee->value)->exists())->toBeFalse();
});

it('adds no late fee to what was paid on time', function () {
    owe($this->driver->id, 1_800, weeksAgo: 1);
    DebtLedger::credit($this->driver, DebtEntryType::Payment, 1_800, 'instapay_payment', side: DebtSide::Driver);

    $this->artisan('payments:sweep-debts')->assertSuccessful();

    expect(DebtEntry::query()->where('type', DebtEntryType::LateFee->value)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| PAY-45: the driver, after the second Friday
|--------------------------------------------------------------------------
*/

it('suspends a driver whose debt missed its second Friday: commutes paused, every rider cancelled with no fee', function () {
    owe($this->driver->id, 1_800, weeksAgo: 2);

    $this->artisan('payments:sweep-debts')->assertSuccessful();

    $booking = Booking::query()->findOrFail($this->bookingId);

    expect(DebtAccount::query()->where('user_id', $this->driver->id)->where('side', 'driver')->sole()->suspended_at)->not->toBeNull()
        ->and(CommuteOffer::query()->findOrFail($this->commuteId)->status)->toBe(CommuteOfferStatus::Paused)
        ->and(CommuteOffer::query()->findOrFail($this->commuteId)->paused_reason)->toBe(CommutePausedReason::CommissionUnpaid)
        ->and($booking->status)->toBe(BookingStatus::CancelledByDriver)
        // Back on the waiting list for when the commute returns.
        ->and(SeatRequest::query()->findOrFail($booking->seat_request_id)->status)->toBe(SeatRequestStatus::Waitlisted)
        // No fee for anybody.
        ->and(DebtEntry::query()->where('type', DebtEntryType::Fee->value)->exists())->toBeFalse()
        ->and(Notification::query()->where('user_id', $this->driver->id)->where('type', 'driving_suspended_for_debt')->exists())->toBeTrue();

    // She keeps Passenger mode; she may not drive again until she pays.
    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/resume")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'DRIVER_COMMISSION_OVERDUE');
});

it('lifts the driver’s suspension once nothing is overdue', function () {
    owe($this->driver->id, 1_800, weeksAgo: 2);
    $this->artisan('payments:sweep-debts')->assertSuccessful();

    $payment = DebtPayment::factory()->create(['user_id' => $this->driver->id, 'side' => DebtSide::Driver, 'amount_piastres' => 1_800]);
    app(ManageDebtAction::class)->confirmPayment($payment, AdminUser::factory()->create());

    expect(DebtAccount::query()->where('user_id', $this->driver->id)->where('side', 'driver')->sole()->suspended_at)->toBeNull();

    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/resume")->assertOk();
});

it('never suspends while a dispute of hers is open', function () {
    owe($this->driver->id, 1_800, weeksAgo: 2);

    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();
    $this->withToken($this->paxToken)->postJson("/api/v1/bookings/{$this->bookingId}/dispute", [
        'reason' => 'العربية مشيت قبل ما أوصل.',
    ])->assertOk();

    $this->artisan('payments:sweep-debts')->assertSuccessful();

    expect(DebtAccount::query()->where('user_id', $this->driver->id)->where('side', 'driver')->sole()->suspended_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| PAY-17, PAY-46: the passenger
|--------------------------------------------------------------------------
*/

it('suspends a passenger the moment her debt goes over 200 EGP, and cancels her seats', function () {
    DebtLedger::charge($this->pax, DebtEntryType::Fee, 20_001, 'passenger_no_show', side: DebtSide::Passenger);

    expect(DebtAccount::query()->where('user_id', $this->pax->id)->where('side', 'passenger')->sole()->suspended_at)->not->toBeNull()
        ->and(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::CancelledByPassenger)
        ->and(Notification::query()->where('user_id', $this->pax->id)->where('type', 'riding_suspended_for_debt')->exists())->toBeTrue();

    // Booking is blocked until she pays.
    $draft = ScheduledTrip::query()->where('commute_offer_id', $this->commuteId)->orderByDesc('trip_date')->first();

    requestSeat($this->paxToken, $this->commuteId, ['scheduledTripId' => $draft->id])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'RIDING_SUSPENDED_FOR_DEBT')
        ->assertJsonPath('error.fields.amountOwedPiastres.0', '20001');

    expect($this->withToken($this->paxToken)->getJson('/api/v1/account/debt')->json('data.passenger.suspended'))->toBeTrue();
});

it('does not suspend a passenger at exactly 200 EGP', function () {
    DebtLedger::charge($this->pax, DebtEntryType::Fee, 20_000, 'passenger_no_show', side: DebtSide::Passenger);

    expect(DebtAccount::query()->where('user_id', $this->pax->id)->where('side', 'passenger')->sole()->suspended_at)->toBeNull();
});

it('suspends a passenger whose fee has stayed unpaid 14 days', function () {
    DebtLedger::charge($this->pax, DebtEntryType::Fee, 2_000, 'passenger_no_show', side: DebtSide::Passenger);
    DebtEntry::query()->where('user_id', $this->pax->id)->update(['created_at' => now()->subDays(14)->subMinute()]);

    $this->artisan('payments:sweep-debts')->assertSuccessful();

    expect(DebtAccount::query()->where('user_id', $this->pax->id)->where('side', 'passenger')->sole()->suspended_at)->not->toBeNull();
});

it('lifts the passenger’s suspension once she owes nothing', function () {
    DebtLedger::charge($this->pax, DebtEntryType::Fee, 20_001, 'passenger_no_show', side: DebtSide::Passenger);

    $payment = DebtPayment::factory()->create(['user_id' => $this->pax->id, 'side' => DebtSide::Passenger, 'amount_piastres' => 20_001]);
    app(ManageDebtAction::class)->confirmPayment($payment, AdminUser::factory()->create());

    expect(DebtAccount::query()->where('user_id', $this->pax->id)->where('side', 'passenger')->sole()->suspended_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| PAY-09, PAY-34: payments and coupons
|--------------------------------------------------------------------------
*/

it('takes nothing off when staff refuse a payment, and tells her why', function () {
    owe($this->driver->id, 1_800, weeksAgo: 0);
    $payment = DebtPayment::factory()->create(['user_id' => $this->driver->id, 'side' => DebtSide::Driver]);

    app(ManageDebtAction::class)->rejectPayment($payment, AdminUser::factory()->create(), 'No transfer with this reference.');

    $mine = $this->withToken($this->driverToken)->getJson('/api/v1/account/debt')->json('data');

    expect($mine['driver']['owedPiastres'])->toBe(1_800)
        ->and($mine['payments'][0]['status'])->toBe('rejected')
        ->and($mine['payments'][0]['decisionNote'])->toBe('No transfer with this reference.');
});

it('decides a payment once', function () {
    owe($this->driver->id, 1_800, weeksAgo: 0);
    $payment = DebtPayment::factory()->create(['user_id' => $this->driver->id, 'side' => DebtSide::Driver]);
    $admin = AdminUser::factory()->create();

    app(ManageDebtAction::class)->confirmPayment($payment, $admin);

    expect(fn () => app(ManageDebtAction::class)->confirmPayment($payment, $admin))
        ->toThrow(DomainException::class);
    expect(DebtEntry::query()->where('type', DebtEntryType::Payment->value)->count())->toBe(1);
});

it('gives the harmed side a coupon of half a fee that was collected, never more than one seat', function () {
    $booking = Booking::query()->findOrFail($this->bookingId);
    $fee = DebtLedger::charge($this->pax, DebtEntryType::Fee, 4_000, 'passenger_no_show', $booking);
    $admin = AdminUser::factory()->create();

    // Not collected yet: RAFEEQ gives back part of money it has, never money it is owed.
    expect(fn () => app(ManageDebtAction::class)->issueCoupon($fee, $admin, 'Driver waited.'))
        ->toThrow(DomainException::class);

    DebtLedger::credit($this->pax, DebtEntryType::Payment, 4_000, 'instapay_payment', side: DebtSide::Passenger);

    $coupon = app(ManageDebtAction::class)->issueCoupon($fee, $admin, 'Driver waited.');
    $seat = intdiv($booking->price_snapshot_piastres, $booking->seats_reserved);

    expect($coupon->user_id)->toBe($this->driver->id)
        ->and($coupon->side)->toBe(DebtSide::Driver)
        ->and($coupon->value_piastres)->toBe(min(2_000, $seat))
        ->and(DebtLedger::balanceOf($this->driver, DebtSide::Driver))->toBe(-$coupon->value_piastres);

    // One coupon per fee.
    expect(fn () => app(ManageDebtAction::class)->issueCoupon($fee, $admin, 'Again.'))
        ->toThrow(DomainException::class);
});
