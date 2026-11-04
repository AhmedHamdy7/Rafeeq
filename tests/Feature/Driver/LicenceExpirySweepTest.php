<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Models\DebtEntry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->first();
    $this->paxToken = verifiedPassenger('01112223344');
    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->trip->id,
    ])->json('data.id'));

    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
});

function lapseLicence(): void
{
    DriverProfile::sole()->forceFill(['licence_expiry' => now()->subDay()->toDateString()])->save();
}

it('pauses the commute and cancels the seat with no fee when the licence has expired', function () {
    lapseLicence();

    $this->artisan('licences:stop-expired')->assertSuccessful();

    $offer = CommuteOffer::query()->findOrFail($this->commuteId);
    $booking = Booking::query()->findOrFail($this->bookingId);

    expect($offer->status)->toBe(CommuteOfferStatus::Paused)
        ->and($offer->paused_reason)->toBe(CommutePausedReason::LicenceExpired)
        ->and($booking->status)->toBe(BookingStatus::CancelledByDriver)
        ->and(SeatRequest::query()->findOrFail($booking->seat_request_id)->status)->toBe(SeatRequestStatus::Waitlisted)
        ->and(DebtEntry::query()->where('type', DebtEntryType::Fee->value)->exists())->toBeFalse()
        ->and(DriverProfile::sole()->status)->toBe(DriverProfileStatus::ExpiredDocuments);
});

it('cancels a seat inside the 16-hour lock too', function () {
    lapseLicence();
    $this->travelTo($this->trip->departure_at->subHours(2));

    $this->artisan('licences:stop-expired')->assertSuccessful();

    expect(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::CancelledByDriver)
        ->and(DebtEntry::query()->where('type', DebtEntryType::Fee->value)->exists())->toBeFalse();
});

it('tells the driver once, however often the sweep runs', function () {
    lapseLicence();

    $this->artisan('licences:stop-expired')->assertSuccessful();
    $this->artisan('licences:stop-expired')->assertSuccessful();

    expect(Notification::query()
        ->where('user_id', $this->driver->id)
        ->where('type', 'driver_licence_expired')
        ->count())->toBe(1);
});

it('leaves a driver with a valid licence alone', function () {
    DriverProfile::sole()->forceFill(['licence_expiry' => now()->addDay()->toDateString()])->save();

    $this->artisan('licences:stop-expired')->assertSuccessful();

    expect(CommuteOffer::query()->findOrFail($this->commuteId)->status)->toBe(CommuteOfferStatus::Published)
        ->and(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::Confirmed)
        ->and(DriverProfile::sole()->status)->toBe(DriverProfileStatus::Approved);
});

it('leaves a suspended driver suspended', function () {
    app(ReviewDriverApplicationAction::class)->suspend(DriverProfile::sole(), AdminUser::factory()->create(), 'Holding while we look into it.');
    lapseLicence();

    $this->artisan('licences:stop-expired')->assertSuccessful();

    expect(DriverProfile::sole()->status)->toBe(DriverProfileStatus::Suspended);
});

it('shows renewal required on the driver card even when the licence verification carries no expiry', function () {
    lapseLicence();

    $this->artisan('licences:stop-expired')->assertSuccessful();

    $this->withToken($this->driverToken)->getJson('/api/v1/driver/verification')
        ->assertOk()
        ->assertJsonPath('data.overall', 'renewal_required');
});

it('refuses resume until the renewed licence is approved', function () {
    lapseLicence();
    $this->artisan('licences:stop-expired')->assertSuccessful();

    $resume = fn () => $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/resume");

    $resume()->assertStatus(422)->assertJsonPath('error.code', 'DRIVER_LICENCE_EXPIRED');

    $this->withToken($this->driverToken)->putJson('/api/v1/driver/application/licence', [
        'nationalId' => '29604120100001',
        'licenceNumber' => 'DL-9931201',
        'licenceExpiry' => now()->addYears(3)->toDateString(),
    ])->assertOk();

    $resume()->assertStatus(422)->assertJsonPath('error.code', 'DRIVER_LICENCE_EXPIRED');

    $this->withToken($this->driverToken)->postJson('/api/v1/driver/application/submit')->assertOk();

    app(ReviewDriverApplicationAction::class)->approve(DriverProfile::sole(), AdminUser::factory()->create());

    $resume()->assertOk();

    expect(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::CancelledByDriver);
});

it('runs on the schedule', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

    expect($commands->contains(fn (string $command) => str_contains($command, 'licences:stop-expired')))->toBeTrue();
});
