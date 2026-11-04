<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
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
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Livewire\Admin\Members;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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
    $this->ops = adminWithRole(AdminRole::Operations);
});

function suspendThroughDashboard(User $driver, string $note = 'Two conduct reports this week, holding for review.')
{
    return Livewire::test(Members::class)
        ->call('open', $driver->id)
        ->call('startForm', 'suspend_driver')
        ->set('note', $note)
        ->call('suspendDriver');
}

it('pauses every commute and cancels the seats with no fee', function () {
    app(ReviewDriverApplicationAction::class)->suspend(DriverProfile::sole(), $this->ops, 'Two conduct reports this week.');

    $offer = CommuteOffer::query()->findOrFail($this->commuteId);
    $booking = Booking::query()->findOrFail($this->bookingId);

    expect($offer->status)->toBe(CommuteOfferStatus::Paused)
        ->and($offer->paused_reason)->toBe(CommutePausedReason::DriverSuspended)
        ->and($booking->status)->toBe(BookingStatus::CancelledByDriver)
        ->and(SeatRequest::query()->findOrFail($booking->seat_request_id)->status)->toBe(SeatRequestStatus::Waitlisted)
        ->and(DebtEntry::query()->where('type', DebtEntryType::Fee->value)->exists())->toBeFalse();
});

it('cancels a seat inside the 16-hour lock too', function () {
    $this->travelTo($this->trip->departure_at->subHours(2));

    app(ReviewDriverApplicationAction::class)->suspend(DriverProfile::sole(), $this->ops, 'Two conduct reports this week.');

    expect(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::CancelledByDriver)
        ->and(DebtEntry::query()->where('type', DebtEntryType::Fee->value)->exists())->toBeFalse();
});

it('tells the driver and keeps the account active', function () {
    app(ReviewDriverApplicationAction::class)->suspend(DriverProfile::sole(), $this->ops, 'Two conduct reports this week.');

    expect(Notification::query()->where('user_id', $this->driver->id)->where('type', 'driver_suspended')->count())->toBe(1)
        ->and($this->driver->fresh()->account_status)->toBe(AccountStatus::Active);
});

it('refuses to resume while suspended', function () {
    app(ReviewDriverApplicationAction::class)->suspend(DriverProfile::sole(), $this->ops, 'Two conduct reports this week.');

    $this->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/resume")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'DRIVER_NOT_ELIGIBLE');
});

it('refuses a second suspension', function () {
    $action = app(ReviewDriverApplicationAction::class);
    $action->suspend(DriverProfile::sole(), $this->ops, 'Two conduct reports this week.');

    expect(fn () => $action->suspend(DriverProfile::sole(), $this->ops, 'Again, by a second reviewer.'))
        ->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe(ErrorCode::DriverApplicationLocked));
});

it('refuses to suspend a draft application', function () {
    DriverProfile::sole()->forceFill(['status' => DriverProfileStatus::Draft->value])->save();

    expect(fn () => app(ReviewDriverApplicationAction::class)->suspend(DriverProfile::sole(), $this->ops, 'Two conduct reports this week.'))
        ->toThrow(DomainException::class);

    expect(DriverProfile::sole()->status)->toBe(DriverProfileStatus::Draft);
});

it('lets staff suspend the driver from the dashboard, and records it', function () {
    actingAsAdmin($this->ops);

    suspendThroughDashboard($this->driver)->assertHasNoErrors();

    $audit = AdminAction::query()->where('action', 'driver.suspend')->sole();

    expect(DriverProfile::sole()->status)->toBe(DriverProfileStatus::Suspended)
        ->and(DriverProfile::sole()->rejection_reason)->toBe('Two conduct reports this week, holding for review.')
        ->and(Booking::query()->findOrFail($this->bookingId)->status)->toBe(BookingStatus::CancelledByDriver)
        ->and($audit->admin_id)->toBe($this->ops->id);
});

it('shows staff how many trips and passengers are affected before they confirm', function () {
    actingAsAdmin($this->ops);

    Livewire::test(Members::class)
        ->call('open', $this->driver->id)
        ->call('startForm', 'suspend_driver')
        ->assertSee(__('admin.members.driver_suspend_impact', ['days' => 1, 'passengers' => 1]));
});

it('requires a reason from staff', function () {
    actingAsAdmin($this->ops);

    suspendThroughDashboard($this->driver, note: 'short')->assertHasErrors('note');

    expect(DriverProfile::sole()->status)->toBe(DriverProfileStatus::Approved);
});

it('refuses staff without the permission', function () {
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(Members::class)
        ->set('openId', $this->driver->id)
        ->set('note', 'Two conduct reports this week, holding for review.')
        ->call('suspendDriver')
        ->assertForbidden();

    expect(DriverProfile::sole()->status)->toBe(DriverProfileStatus::Approved);
});

it('only offers the action for a driver who can be suspended', function () {
    actingAsAdmin($this->ops);

    Livewire::test(Members::class)->call('open', $this->driver->id)
        ->assertSee(__('admin.members.suspend_driver'));

    app(ReviewDriverApplicationAction::class)->suspend(DriverProfile::sole(), $this->ops, 'Two conduct reports this week.');

    Livewire::test(Members::class)->call('open', $this->driver->id)
        ->assertDontSee(__('admin.members.suspend_driver'))
        ->assertSee(__('admin.members.driver_is_suspended'));
});
