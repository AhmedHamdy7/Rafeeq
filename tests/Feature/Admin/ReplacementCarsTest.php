<?php

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleRejectedItem;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Notification\Models\Notification;
use App\Livewire\Admin\ReplacementCars;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver();

    test()->withToken($this->driverToken)->postJson('/api/v1/driver/vehicles', [
        'make' => 'Kia', 'model' => 'Cerato', 'year' => 2021, 'colour' => 'white',
        'plateNumber' => 'ق ن ر 7788', 'seats' => 5,
        'transmission' => 'automatic', 'fuelType' => 'petrol',
    ])->assertStatus(201);

    $this->replacement = Vehicle::query()->where('plate_number', 'ق ن ر 7788')->sole();
    $this->reviewer = adminWithRole(AdminRole::Verification);
});

it('lists a replacement car of an approved driver, and not the cars of pending applications', function () {
    $pendingToken = readyDriverApplicant('01098765432', 'dev-2');
    completeDriverApplication($pendingToken, 2);
    test()->withToken($pendingToken)->postJson('/api/v1/driver/application/submit')->assertOk();

    $applicationCar = Vehicle::query()
        ->whereHas('driverProfile', fn ($query) => $query->where('status', DriverProfileStatus::PendingReview->value))
        ->sole();

    actingAsAdmin($this->reviewer);

    $this->get(route('admin.replacement-cars'))->assertOk()->assertSee('ق ن ر 7788');

    Livewire::test(ReplacementCars::class)
        ->assertOk()
        ->assertSee('ق ن ر 7788')
        ->assertDontSee($applicationCar->plate_number)
        ->assertSee(__('admin.replacement_cars.approve'));
});

it('approves a replacement car with the reviewed seats, tier and AC, logs it, and lets the driver switch to it', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(ReplacementCars::class)
        ->call('approve', $this->replacement->id)
        ->assertHasErrors("cars.{$this->replacement->id}")
        ->set("cars.{$this->replacement->id}.approvedSeats", 4)
        ->set("cars.{$this->replacement->id}.tier", 'comfort')
        ->set("cars.{$this->replacement->id}.hasAc", true)
        ->call('approve', $this->replacement->id)
        ->assertHasNoErrors()
        ->assertDontSee('ق ن ر 7788');

    $vehicle = $this->replacement->refresh();

    expect($vehicle->verification_status)->toBe(VehicleVerificationStatus::Approved)
        ->and($vehicle->approved_seats)->toBe(4)
        ->and($vehicle->tier)->toBe(VehicleTier::Comfort)
        ->and($vehicle->has_ac)->toBeTrue()
        ->and($vehicle->is_active)->toBeFalse()
        ->and($vehicle->driverProfile->status)->toBe(DriverProfileStatus::Approved);

    $entry = AdminAction::query()->where('action', 'vehicle.approve_replacement')->sole();

    expect($entry->admin_id)->toBe($this->reviewer->id)
        ->and($entry->old_value)->toBe(['verification_status' => 'pending'])
        ->and($entry->new_value)->toEqual(['verification_status' => 'approved', 'approved_seats' => 4, 'tier' => 'comfort', 'has_ac' => true]);

    expect(Notification::query()->where('type', 'driver_item_approved')->where('data->vehicleId', $vehicle->id)->exists())->toBeTrue();

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/driver/vehicles/{$vehicle->id}/activate")
        ->assertOk();

    expect($vehicle->refresh()->is_active)->toBeTrue();
});

it('refuses approved seats outside the allowed range and leaves the car pending', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(ReplacementCars::class)
        ->set("cars.{$this->replacement->id}.approvedSeats", (int) config('rafeeq.driver.max_approved_seats') + 1)
        ->call('approve', $this->replacement->id)
        ->assertHasErrors("cars.{$this->replacement->id}");

    expect($this->replacement->refresh()->verification_status)->toBe(VehicleVerificationStatus::Pending)
        ->and($this->replacement->approved_seats)->toBeNull()
        ->and(AdminAction::query()->where('action', 'vehicle.approve_replacement')->exists())->toBeFalse();
});

it('rejects a replacement car with an item and a reason, logs it, and leaves the driver approved', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(ReplacementCars::class)
        ->call('openRejection', $this->replacement->id, 'vehicle_registration')
        ->set('reason', 'Too short')
        ->call('reject')
        ->assertHasErrors('reason')
        ->set('reason', 'The registration plate does not match the car in the photo.')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertSet('rejectingId', null);

    $vehicle = $this->replacement->refresh();

    expect($vehicle->verification_status)->toBe(VehicleVerificationStatus::Rejected)
        ->and($vehicle->rejected_item)->toBe(VehicleRejectedItem::Registration)
        ->and($vehicle->rejection_reason)->toContain('plate does not match')
        ->and($vehicle->driverProfile->status)->toBe(DriverProfileStatus::Approved);

    $entry = AdminAction::query()->where('action', 'vehicle.reject_replacement')->sole();

    expect($entry->admin_id)->toBe($this->reviewer->id)
        ->and($entry->reason)->toContain('plate does not match')
        ->and($entry->new_value)->toEqual(['verification_status' => 'rejected', 'rejected_item' => 'vehicle_registration']);

    expect(Notification::query()->where('type', 'driver_item_rejected')->where('data->vehicleId', $vehicle->id)->exists())->toBeTrue();
});

it('refuses to decide a car that is no longer waiting', function () {
    actingAsAdmin($this->reviewer);

    $page = Livewire::test(ReplacementCars::class)
        ->set("cars.{$this->replacement->id}.approvedSeats", 3);

    $this->replacement->forceFill(['verification_status' => VehicleVerificationStatus::Approved->value])->save();

    $page->call('approve', $this->replacement->id)->assertNotFound();
});

it('keeps out staff without the driver permissions', function () {
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(ReplacementCars::class)->assertForbidden();

    $this->get(route('admin.replacement-cars'))->assertForbidden();
});

it('lets staff who may only view the queue see it but not decide', function () {
    $viewer = adminWithRole(AdminRole::Support);
    $viewer->givePermissionTo(AdminPermission::DriverView->value);

    expect($viewer->can(AdminPermission::DriverDecide->value))->toBeFalse();

    actingAsAdmin($viewer);

    $page = Livewire::test(ReplacementCars::class)
        ->assertOk()
        ->assertSee('ق ن ر 7788')
        ->assertDontSee(__('admin.replacement_cars.approve'))
        ->assertSee(__('admin.queue.view_only'));

    $page->set("cars.{$this->replacement->id}.approvedSeats", 3)
        ->call('approve', $this->replacement->id)
        ->assertForbidden();

    Livewire::test(ReplacementCars::class)
        ->set('rejectingId', $this->replacement->id)
        ->set('rejectionItem', 'vehicle_details')
        ->set('reason', 'The car details do not match the registration.')
        ->call('reject')
        ->assertForbidden();

    expect($this->replacement->refresh()->verification_status)->toBe(VehicleVerificationStatus::Pending)
        ->and(AdminAction::query()->whereIn('action', ['vehicle.approve_replacement', 'vehicle.reject_replacement'])->exists())->toBeFalse();
});
