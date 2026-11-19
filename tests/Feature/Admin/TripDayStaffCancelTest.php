<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Livewire\Admin\Directory\TripDayDetail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');
    fakeOtpSender();

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$commuteId}/publish")->assertOk();

    $this->trip = ScheduledTrip::query()->orderBy('trip_date')->firstOrFail();
    $this->paxToken = verifiedPassenger('01112223344', device: 'pax-1');
    $this->bookingId = approveSeat($this->driverToken, requestSeat($this->paxToken, $commuteId, ['scheduledTripId' => $this->trip->id])->assertStatus(201)->json('data.id'));
});

it('cancels a day from the dashboard: passengers told it was YuGo, no fee, no reliability drop', function () {
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(TripDayDetail::class, ['trip' => $this->trip])
        ->assertSee(__('admin/records.trip.cancel'))
        ->call('startCancel')
        ->set('reason', 'Ring Road closed by the authorities')
        ->call('cancelDay')
        ->assertHasNoErrors()
        ->assertSet('done', __('admin/records.trip.cancelled_done'))
        ->assertDontSee(__('admin/records.trip.cancel_confirm'));

    $driver = User::query()->where('phone_e164', '+201012345678')->sole();
    $booking = Booking::query()->findOrFail($this->bookingId);

    expect($this->trip->refresh()->status)->toBe(ScheduledTripStatus::Cancelled)
        ->and($booking->cancel_code)->toBe('cancelled_by_platform')
        ->and((int) $booking->cancellation_fee_piastres)->toBe(0)
        ->and(DebtEntry::query()->count())->toBe(0)
        ->and(ReliabilityEvent::query()->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $booking->passenger_user_id)->where('type', 'trip_day_cancelled_by_platform')->exists())->toBeTrue()
        ->and(Notification::query()->where('user_id', $driver->id)->where('type', 'staff_message')->exists())->toBeTrue()
        ->and(AdminAction::query()->where('action', 'trip.cancel_by_staff')->value('reason'))->toBe('Ring Road closed by the authorities');

    test()->flushHeaders()->withToken($this->paxToken)->getJson("/api/v1/bookings/{$this->bookingId}")
        ->assertOk()->assertJsonPath('data.cancelledReason', 'cancelled_by_platform');
});

it('needs a reason, and hides the button on a day that is no longer scheduled', function () {
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(TripDayDetail::class, ['trip' => $this->trip])
        ->call('startCancel')
        ->set('reason', '')
        ->call('cancelDay')
        ->assertHasErrors('reason');

    expect($this->trip->refresh()->status)->toBe(ScheduledTripStatus::Scheduled);

    $this->trip->forceFill(['status' => ScheduledTripStatus::Cancelled->value])->save();

    Livewire::test(TripDayDetail::class, ['trip' => $this->trip])
        ->assertDontSee(__('admin/records.trip.cancel'));
});

it('keeps the action from staff who can see trip days but not moderate commutes', function () {
    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));

    Livewire::test(TripDayDetail::class, ['trip' => $this->trip])
        ->assertDontSee(__('admin/records.trip.cancel'))
        ->call('startCancel')
        ->assertForbidden();

    expect($this->trip->refresh()->status)->toBe(ScheduledTripStatus::Scheduled);
});
