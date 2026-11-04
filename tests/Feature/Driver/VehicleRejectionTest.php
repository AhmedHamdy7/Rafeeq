<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleRejectedItem;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Enums\VerificationLogAction;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VerificationLog;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Models\Notification;
use App\Domains\Shared\Exceptions\DomainException;
use App\Livewire\Admin\DriverApplications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');

    $this->reviewer = adminWithRole(AdminRole::Verification);

    $this->token = readyDriverApplicant();
    completeDriverApplication($this->token);

    test()->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();

    $this->profile = DriverProfile::query()->sole();
    $this->vehicle = Vehicle::query()->sole();
});

function rejectCarOnPage(string $item, string $reason = 'The registration photo is too blurry to read.'): mixed
{
    actingAsAdmin(test()->reviewer);

    return Livewire::test(DriverApplications::class)
        ->call('openCarRejection', test()->vehicle->id, $item)
        ->set('reason', $reason)
        ->call('rejectCar', test()->profile->user_id);
}

function carChecklist(): array
{
    $data = test()->withToken(test()->token)->getJson('/api/v1/driver/verification')->assertOk()->json('data');
    $data['byKey'] = collect($data['items'])->keyBy('key')->all();

    return $data;
}

it('rejects the registration on its own and stores the reason on the car', function () {
    rejectCarOnPage('vehicle_registration')->assertHasNoErrors();

    $vehicle = $this->vehicle->refresh();

    expect($vehicle->verification_status)->toBe(VehicleVerificationStatus::Rejected)
        ->and($vehicle->rejected_item)->toBe(VehicleRejectedItem::Registration)
        ->and($vehicle->rejection_reason)->toBe('The registration photo is too blurry to read.')
        ->and($this->profile->refresh()->status)->toBe(DriverProfileStatus::Rejected);
});

it('names the rejected registration on its item and in actionItem, and leaves the details alone', function () {
    rejectCarOnPage('vehicle_registration');

    $data = carChecklist();

    expect($data['overall'])->toBe('action_required')
        ->and($data['actionItem'])->toBe([
            'key' => 'vehicle_registration',
            'status' => 'action_needed',
            'rejectionReason' => 'The registration photo is too blurry to read.',
        ])
        ->and($data['byKey']['vehicle_registration']['status'])->toBe('action_needed')
        ->and($data['byKey']['vehicle_registration']['rejectionReason'])->toBe('The registration photo is too blurry to read.')
        ->and($data['byKey']['vehicle_details']['status'])->toBe('in_progress')
        ->and($data['byKey']['vehicle_details']['rejectionReason'])->toBeNull();
});

it('names the rejected details on their item and in actionItem, and leaves the registration alone', function () {
    rejectCarOnPage('vehicle_details', 'The plate number does not match the car.');

    $data = carChecklist();

    expect($data['actionItem'])->toBe([
        'key' => 'vehicle_details',
        'status' => 'action_needed',
        'rejectionReason' => 'The plate number does not match the car.',
    ])
        ->and($data['byKey']['vehicle_registration']['status'])->toBe('in_progress')
        ->and($data['byKey']['vehicle_registration']['rejectionReason'])->toBeNull();
});

it('reads the reason from the car, not from the profile', function () {
    rejectCarOnPage('vehicle_details', 'The plate number does not match the car.');

    $this->profile->refresh()->forceFill(['rejection_reason' => 'Something else entirely.'])->save();

    expect(carChecklist()['actionItem']['rejectionReason'])->toBe('The plate number does not match the car.');
});

it('still reads the application key when the whole application is rejected', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(DriverApplications::class)
        ->set('reason', 'This licence belongs to somebody else.')
        ->call('reject', $this->profile->user_id)
        ->assertHasNoErrors();

    expect(carChecklist()['actionItem'])->toBe([
        'key' => 'application',
        'status' => 'action_needed',
        'rejectionReason' => 'This licence belongs to somebody else.',
    ])
        ->and($this->vehicle->refresh()->verification_status)->not->toBe(VehicleVerificationStatus::Rejected);
});

it('shows the rejected car when another car is the active one', function () {
    $other = Vehicle::factory()->create(['driver_profile_id' => $this->profile->user_id, 'is_active' => false]);

    $this->vehicle = $other;

    rejectCarOnPage('vehicle_details', 'The plate number does not match the car.');

    expect(carChecklist()['actionItem']['key'])->toBe('vehicle_details');
});

it('records the decision in both audit trails', function () {
    rejectCarOnPage('vehicle_registration');

    $log = VerificationLog::query()->where('entity_type', 'vehicle')->where('action', VerificationLogAction::Reject->value)->sole();

    expect($log->entity_id)->toBe($this->vehicle->id)
        ->and($log->reason)->toBe('The registration photo is too blurry to read.')
        ->and($log->new_value)->toMatchArray(['verification_status' => 'rejected', 'rejected_item' => 'vehicle_registration'])
        ->and(VerificationLog::query()->where('entity_type', 'driver_profile')->where('action', VerificationLogAction::Reject->value)->exists())->toBeTrue();

    expect(AdminAction::query()->where('action', 'driver.reject_car')->sole()->reason)
        ->toBe('The registration photo is too blurry to read.');
});

it('tells the driver which item to replace, once', function () {
    rejectCarOnPage('vehicle_registration');

    $notice = Notification::query()->where('user_id', $this->profile->user_id)->where('type', NotificationType::DriverItemRejected->value)->sole();

    expect($notice->data)->toMatchArray([
        'itemKey' => 'vehicle_registration',
        'rejectionReason' => 'The registration photo is too blurry to read.',
    ])
        ->and(Notification::query()->where('type', NotificationType::DriverDocumentsRejected->value)->exists())->toBeFalse();
});

it('requires a reason of real length', function () {
    rejectCarOnPage('vehicle_registration', 'short')->assertHasErrors('reason');

    expect($this->vehicle->refresh()->verification_status)->toBe(VehicleVerificationStatus::Pending);
});

it('refuses an unknown item', function () {
    rejectCarOnPage('insurance')->assertHasErrors('carRejectionItem');

    expect($this->vehicle->refresh()->verification_status)->toBe(VehicleVerificationStatus::Pending);
});

it('refuses a car from a different application', function () {
    $other = Vehicle::factory()->create();

    actingAsAdmin($this->reviewer);

    Livewire::test(DriverApplications::class)
        ->call('openCarRejection', $other->id, 'vehicle_details')
        ->set('reason', 'The plate number does not match the car.')
        ->call('rejectCar', $this->profile->user_id)
        ->assertNotFound();

    expect($other->refresh()->verification_status)->not->toBe(VehicleVerificationStatus::Rejected);
});

it('refuses a decision on an application that is not waiting', function () {
    rejectCarOnPage('vehicle_details');

    app(ReviewDriverApplicationAction::class)->rejectVehicle(
        $this->profile->refresh(),
        $this->vehicle->refresh(),
        $this->reviewer,
        VehicleRejectedItem::Registration,
        'The registration photo is too blurry to read.',
    );
})->throws(DomainException::class);

it('refuses a resubmission until the rejected item is replaced', function () {
    rejectCarOnPage('vehicle_registration');

    test()->withToken($this->token)->postJson('/api/v1/driver/application/submit')
        ->assertStatus(422)
        ->assertJsonPath('error.fields.missing', ['vehicle_registration']);
});

it('clears a rejected registration when a registration is replaced, and goes back to review on resubmit', function () {
    rejectCarOnPage('vehicle_registration');

    test()->withToken($this->token)->postJson("/api/v1/driver/vehicles/{$this->vehicle->id}/documents", [
        'type' => 'registration',
        'file' => UploadedFile::fake()->image('registration.jpg', 1000, 700),
    ])->assertStatus(201);

    $vehicle = $this->vehicle->refresh();

    expect($vehicle->verification_status)->toBe(VehicleVerificationStatus::Pending)
        ->and($vehicle->rejection_reason)->toBeNull()
        ->and($vehicle->rejected_item)->toBeNull()
        ->and(carChecklist()['actionItem'])->toBeNull()
        ->and($this->profile->refresh()->status)->toBe(DriverProfileStatus::Draft)
        ->and($this->profile->rejection_reason)->toBeNull();

    test()->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();

    $data = carChecklist();

    expect($this->profile->refresh()->status)->toBe(DriverProfileStatus::PendingReview)
        ->and($data['overall'])->toBe('under_review')
        ->and($data['byKey']['vehicle_registration']['status'])->toBe('under_review');
});

it('does not clear a rejected registration for an insurance upload or a details edit', function () {
    rejectCarOnPage('vehicle_registration');

    test()->withToken($this->token)->postJson("/api/v1/driver/vehicles/{$this->vehicle->id}/documents", [
        'type' => 'insurance',
        'file' => UploadedFile::fake()->image('insurance.jpg', 1000, 700),
    ])->assertStatus(201);

    test()->withToken($this->token)->patchJson("/api/v1/driver/vehicles/{$this->vehicle->id}", ['colour' => 'White'])->assertOk();

    expect($this->vehicle->refresh()->verification_status)->toBe(VehicleVerificationStatus::Rejected)
        ->and($this->profile->refresh()->status)->toBe(DriverProfileStatus::Rejected);
});

it('clears rejected details when the car is edited', function () {
    rejectCarOnPage('vehicle_details', 'The plate number does not match the car.');

    test()->withToken($this->token)->patchJson("/api/v1/driver/vehicles/{$this->vehicle->id}", ['plateNumber' => 'XYZ 9876'])->assertOk();

    expect($this->vehicle->refresh()->verification_status)->toBe(VehicleVerificationStatus::Pending)
        ->and($this->vehicle->rejection_reason)->toBeNull();

    test()->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();
});

it('does not clear rejected details for a save that changes nothing, or for a registration upload', function () {
    rejectCarOnPage('vehicle_details', 'The plate number does not match the car.');

    test()->withToken($this->token)->patchJson("/api/v1/driver/vehicles/{$this->vehicle->id}", ['colour' => $this->vehicle->colour])->assertOk();

    test()->withToken($this->token)->postJson("/api/v1/driver/vehicles/{$this->vehicle->id}/documents", [
        'type' => 'registration',
        'file' => UploadedFile::fake()->image('registration.jpg', 1000, 700),
    ])->assertStatus(201);

    expect($this->vehicle->refresh()->verification_status)->toBe(VehicleVerificationStatus::Rejected);
});

it('offers both rejections on the page', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(DriverApplications::class)
        ->assertSee(__('admin.drivers.car.reject_registration'))
        ->assertSee(__('admin.drivers.car.reject_details'))
        ->call('openCarRejection', $this->vehicle->id, 'vehicle_details')
        ->assertSet('carRejectionVehicleId', $this->vehicle->id)
        ->assertSee(__('admin.drivers.confirm_reject'));
});
