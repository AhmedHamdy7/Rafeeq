<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Identity\Models\User;
use App\Domains\Verification\Support\DocumentStorage;
use App\Livewire\Admin\DriverApplications;
use App\Livewire\Admin\Members;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');

    $token = readyDriverApplicant();
    completeDriverApplication($token);

    test()->withToken($token)->postJson('/api/v1/driver/application/submit')->assertOk();

    $this->application = DriverProfile::query()
        ->where('status', DriverProfileStatus::PendingReview->value)
        ->sole();
});

it('shows the full driver application before approval', function () {
    actingAsAdmin(adminWithRole(AdminRole::Verification));

    $document = VehicleDocument::sole();

    Livewire::test(DriverApplications::class)
        ->assertSee(__('admin.details.show_all'))
        ->assertSee('+201012345678')
        ->assertSee('مريم حسن')
        ->assertSee(__('admin.details.values.woman'))
        ->assertSee('12 Apr 1996')
        ->assertSee('ABC 1235')
        ->assertSee('2019')
        ->assertSee(__('admin.details.values.petrol'))
        ->assertSee(route('admin.files.vehicle-document', $document->id))
        ->assertSee(__('admin.files.open', ['what' => __('admin.files.kinds.licence_front')]))
        ->assertDontSee('29604120100001')
        ->assertDontSee('DL-9931201');
});

it('shows the licence and national ID numbers only after a reviewer asks, and records it', function () {
    $reviewer = actingAsAdmin(adminWithRole(AdminRole::Verification));

    Livewire::test(DriverApplications::class)
        ->call('revealNumbers', $this->application->user_id)
        ->assertSee('29604120100001')
        ->assertSee('DL-9931201');

    $audit = AdminAction::query()->where('action', 'driver.view_identity_numbers')->sole();

    expect($audit->admin_id)->toBe($reviewer->id)
        ->and($audit->entity_id)->toBe($this->application->user_id);
});

it('keeps documents and numbers from staff without document permission', function () {
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(DriverApplications::class)
        ->assertSee('ABC 1235')
        ->assertDontSee(route('admin.files.vehicle-document', VehicleDocument::sole()->id))
        ->assertDontSee(__('admin.details.reveal_numbers'))
        ->call('revealNumbers', $this->application->user_id)
        ->assertForbidden();
});

it('opens a car paper for a reviewer, and records that they did', function () {
    $document = VehicleDocument::sole();
    $reviewer = actingAsAdmin(adminWithRole(AdminRole::Verification));

    $this->get(route('admin.files.vehicle-document', $document->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect(AdminAction::query()->where('action', 'vehicle.view_document')->sole()->admin_id)->toBe($reviewer->id);
});

it('refuses a car paper to staff without document permission', function () {
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    $this->get(route('admin.files.vehicle-document', VehicleDocument::sole()->id))->assertForbidden();
});

it('refuses a car paper whose bytes changed after upload', function () {
    $document = VehicleDocument::sole();
    app(DocumentStorage::class)->disk()->put($document->file_path, 'altered');
    actingAsAdmin(adminWithRole(AdminRole::Verification));

    $this->get(route('admin.files.vehicle-document', $document->id))->assertStatus(409);
});

it('opens a profile photo for a reviewer', function () {
    $member = User::sole();
    app(DocumentStorage::class)->disk()->put('users/'.$member->id.'/avatar.jpg', 'photo-bytes');
    $member->forceFill(['profile_photo_path' => 'users/'.$member->id.'/avatar.jpg'])->save();
    actingAsAdmin(adminWithRole(AdminRole::Verification));

    $this->get(route('admin.files.photo', $member->id))->assertOk()->assertSee('photo-bytes', escape: false);

    expect(AdminAction::query()->where('action', 'member.view_photo')->count())->toBe(1);
});

it('shows a passenger’s full data on their member profile', function () {
    $passenger = User::factory()->create([
        'full_name' => 'Salma Adel',
        'email' => 'salma@example.com',
        'registered_role' => 'passenger',
    ]);
    actingAsAdmin(adminWithRole(AdminRole::Verification));

    Livewire::test(Members::class)
        ->call('open', $passenger->id)
        ->assertSee(__('admin.details.show_all'))
        ->assertSee('salma@example.com')
        ->assertSee($passenger->phone_e164)
        ->assertSee(__('admin.details.values.passenger'))
        ->assertSee(__('admin.details.trips_as_passenger'))
        ->assertDontSee(__('admin.details.driver'));
});
