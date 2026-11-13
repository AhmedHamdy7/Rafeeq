<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Trip\Models\MemberWarning;
use App\Livewire\Admin\Directory\BookingDetail;
use App\Livewire\Admin\Directory\CommuteDetail;
use App\Livewire\Admin\Directory\VehicleDetail;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/**
 * The directory's detail pages: vehicle (E03–E05), commute (C02), trip day (Y02, Y03) and
 * booking (B03, B04).
 */
it('opens every detail page for operations staff', function () {
    $booking = Booking::factory()->create();
    $trip = $booking->scheduledTrip;
    $commute = $trip->commuteOffer;
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    $this->get(route('admin.vehicles.show', $commute->vehicle_id))->assertOk();
    $this->get(route('admin.commutes.show', $commute->id))->assertOk();
    $this->get(route('admin.trip-days.show', $trip->id))->assertOk()->assertSee(route('admin.bookings.show', $booking->id), false);
    $this->get(route('admin.bookings.show', $booking->id))->assertOk();
});

it('keeps the detail pages from staff without the permission', function () {
    $booking = Booking::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    $this->get(route('admin.bookings.show', $booking->id))->assertForbidden();
    $this->get(route('admin.trip-days.show', $booking->scheduled_trip_id))->assertForbidden();
});

it('suspends a car after a report, pausing its commutes, and reinstates it', function () {
    $commute = CommuteOffer::factory()->published()->create();
    $vehicle = $commute->vehicle;
    $vehicle->forceFill(['verification_status' => VehicleVerificationStatus::Approved->value])->save();
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(VehicleDetail::class, ['vehicle' => $vehicle])
        ->call('start', 'suspend')
        ->set('reason', 'Passenger reported a broken seat belt')
        ->call('act')
        ->assertHasNoErrors()
        ->assertSet('done', __('admin/records.vehicle.done.suspend'));

    expect($vehicle->refresh()->verification_status)->toBe(VehicleVerificationStatus::Suspended)
        ->and($commute->refresh()->status)->toBe(CommuteOfferStatus::Paused);

    Livewire::test(VehicleDetail::class, ['vehicle' => $vehicle])->call('start', 'reinstate')->call('act')->assertHasNoErrors();

    expect($vehicle->refresh()->verification_status)->toBe(VehicleVerificationStatus::Approved);
});

it('keeps a car from staff without the driver desk', function () {
    $commute = CommuteOffer::factory()->published()->create();
    actingAsAdmin(adminWithRole(AdminRole::SafetyLead));
    $this->get(route('admin.vehicles.show', $commute->vehicle_id))->assertForbidden();
});

it('resumes a women-only commute after a gender review, with a reason', function () {
    $commute = CommuteOffer::factory()->published()->create();
    app(ChangeCommuteStatusAction::class)->pause($commute, CommutePausedReason::GenderReview);
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(CommuteDetail::class, ['commute' => $commute->refresh()])
        ->call('startResume')
        ->set('reason', 'Checked the new ID card')
        ->call('resume')
        ->assertHasNoErrors();

    expect($commute->refresh()->status)->toBe(CommuteOfferStatus::Published)
        ->and(AdminAction::query()->where('action', 'commute.resume_after_review')->sole()->reason)->toBe('Checked the new ID card');
});

it('refuses to resume a commute paused for any other reason', function () {
    $commute = CommuteOffer::factory()->published()->create();
    app(ChangeCommuteStatusAction::class)->pause($commute, CommutePausedReason::ByDriver);
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(CommuteDetail::class, ['commute' => $commute->refresh()])
        ->assertViewHas('mayResume', false)
        ->call('startResume')
        ->set('reason', 'Trying anyway')
        ->call('resume')
        ->assertStatus(409);
});

it('cancels a seat for a member with no fee and nothing on their record', function () {
    $booking = Booking::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(BookingDetail::class, ['booking' => $booking])
        ->call('startCancel')
        ->set('reason', 'She called: she moved house')
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertSet('done', __('admin/records.booking.cancelled'));

    expect($booking->refresh()->status)->toBe(BookingStatus::CancelledByPassenger)
        ->and($booking->cancelled_reason)->toBe('staff_cancelled')
        ->and(MemberWarning::query()->where('booking_id', $booking->id)->exists())->toBeFalse()
        ->and(ReliabilityEvent::query()->where('booking_id', $booking->id)->exists())->toBeFalse()
        ->and(AdminAction::query()->where('action', 'booking.cancel')->sole()->reason)->toBe('She called: she moved house');
});

it('has every record string in both languages', function () {
    $keys = fn (string $locale) => array_keys(Arr::dot(trans('admin/records', [], $locale)));

    expect($keys('ar'))->toEqualCanonicalizing($keys('en'));
});
