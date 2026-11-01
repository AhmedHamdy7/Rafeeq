<?php

use App\Domains\Admin\Actions\SettleIncidentMoneyAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Safety\Enums\IncidentCategory;
use App\Domains\Safety\Models\Incident;
use App\Domains\Trip\Actions\RecordMemberWarningAction;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Support\Facades\Storage;

/**
 * The money behind three trip-day outcomes: the early-drop check (TRIP-20, PAY-28), payment
 * disputes (PAY-31, PAY-32) and women-only misuse (PAY-47); and a false-arrival report putting her
 * no-show under review (TRIP-17).
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
    $this->price = Booking::query()->findOrFail($this->bookingId)->price_snapshot_piastres;
});

/*
|--------------------------------------------------------------------------
| TRIP-20, PAY-28: "Did you ask to get off here?"
|--------------------------------------------------------------------------
*/

it('asks her when the driver sets her down far before her point', function () {
    underway($this->driverToken, $this->tripId);
    checkIn($this->driverToken, $this->tripId, ['bookingId' => $this->bookingId])->assertOk();

    // The car is still at her pickup — kilometres from where she was going.
    $pickup = atPickupOf($this->bookingId);
    reportPosition($this->driverToken, $this->tripId, [position($pickup['lat'], $pickup['lng'])])->assertSuccessful();

    $this->withToken($this->driverToken)
        ->postJson("/api/v1/trips/{$this->tripId}/drop-off", ['bookingId' => $this->bookingId])
        ->assertOk();

    $booking = Booking::query()->findOrFail($this->bookingId);

    expect($booking->early_drop_asked_at)->not->toBeNull()
        ->and($booking->early_drop_distance_meters)->toBeGreaterThan(1_000);

    $this->withToken($this->paxToken)->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()
        ->assertJsonPath('data.earlyDrop.answer', null);
});

it('keeps the full fare when she says she asked', function () {
    Booking::query()->whereKey($this->bookingId)->update(['early_drop_asked_at' => now(), 'early_drop_distance_meters' => 3_000]);

    $this->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/early-drop", ['askedToGetOff' => true])
        ->assertOk()
        ->assertJsonPath('data.earlyDrop.answer', 'yes');

    expect(DebtLedger::owedBy($this->driver, DebtSide::Driver))->toBe(0)
        ->and(Incident::query()->exists())->toBeFalse();
});

it('charges the driver her whole seat, gives her the fare back and opens a report when she did not ask', function () {
    Booking::query()->whereKey($this->bookingId)->update(['early_drop_asked_at' => now(), 'early_drop_distance_meters' => 3_000]);

    $this->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/early-drop", ['askedToGetOff' => false])
        ->assertOk()
        ->assertJsonPath('data.earlyDrop.answer', 'no');

    expect(DebtLedger::owedBy($this->driver, DebtSide::Driver))->toBe($this->price)
        ->and(DebtLedger::balanceOf($this->pax, DebtSide::Passenger))->toBe(-$this->price)
        ->and(Incident::query()->sole()->category)->toBe(IncidentCategory::DroppedEarly);

    // Once.
    $this->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/early-drop", ['askedToGetOff' => false])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EARLY_DROP_NOT_ASKED');
});

it('refuses an answer nobody asked for', function () {
    $this->withToken($this->paxToken)
        ->postJson("/api/v1/bookings/{$this->bookingId}/early-drop", ['askedToGetOff' => false])
        ->assertStatus(409);
});

/*
|--------------------------------------------------------------------------
| PAY-31, PAY-32, PAY-47: what staff decide
|--------------------------------------------------------------------------
*/

function reportOn(string $bookingId, User $reporter, IncidentCategory $category): Incident
{
    return Incident::factory()->create([
        'booking_id' => $bookingId,
        'reporter_user_id' => $reporter->id,
        'category' => $category,
    ]);
}

it('charges a passenger who lied about paying 150%, and makes the driver whole', function () {
    $incident = reportOn($this->bookingId, $this->driver, IncidentCategory::Payment);

    app(SettleIncidentMoneyAction::class)->paymentDispute($incident, AdminUser::factory()->create(), 'passenger_lied');

    expect(DebtLedger::owedBy($this->pax, DebtSide::Passenger))->toBe(intdiv($this->price * 150, 100))
        ->and(DebtLedger::balanceOf($this->driver, DebtSide::Driver))->toBe(-$this->price);
});

it('charges a driver who lied about not being paid 50%', function () {
    $incident = reportOn($this->bookingId, $this->driver, IncidentCategory::Payment);

    app(SettleIncidentMoneyAction::class)->paymentDispute($incident, AdminUser::factory()->create(), 'driver_lied');

    expect(DebtLedger::owedBy($this->driver, DebtSide::Driver))->toBe(intdiv($this->price * 50, 100));
});

it('charges a man confirmed to have booked a women-only commute the whole seat, and suspends him', function () {
    $incident = reportOn($this->bookingId, $this->driver, IncidentCategory::WomenOnlyMisuse);

    app(SettleIncidentMoneyAction::class)->womenOnlyMisuse($incident, AdminUser::factory()->create());

    expect(DebtLedger::owedBy($this->pax, DebtSide::Passenger))->toBe($this->price)
        ->and(DebtAccount::query()->where('user_id', $this->pax->id)->where('side', 'passenger')->sole()->suspended_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| TRIP-17: false arrival
|--------------------------------------------------------------------------
*/

it('puts her no-show under review when she reports a false arrival', function () {
    $warning = app(RecordMemberWarningAction::class)->record($this->pax, WarningKind::NoShow, Booking::query()->findOrFail($this->bookingId));

    $this->withToken($this->paxToken)->postJson('/api/v1/incidents', [
        'category' => 'false_arrival',
        'bookingId' => $this->bookingId,
        'description' => 'He tapped arrived from two streets away.',
    ])->assertSuccessful();

    expect(MemberWarning::query()->findOrFail($warning->id)->disputed_at)->not->toBeNull();
});
