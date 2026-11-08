<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use App\Livewire\Admin\DriverApplications;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');

    $token = signIn(phone: '01012345678', devicePublicId: 'dev-1')['session']['accessToken'];
    completeBasicProfile($token);
    submitGovernmentId($token);
    submitSelfie($token);
    test()->withToken($token)->postJson('/api/v1/driver/application')->assertStatus(201);
    completeDriverApplication($token);

    $this->application = DriverProfile::query()->sole();
    $this->id = UserVerification::query()->where('type', VerificationType::GovernmentId->value)->sole();
    $this->selfie = UserVerification::query()->where('type', VerificationType::Selfie->value)->sole();
});

it('lets a reviewer approve the ID and the selfie from the driver card, then the driver', function () {
    $reviewer = actingAsAdmin(adminWithRole(AdminRole::Verification));

    expect($this->application->status)->toBe(DriverProfileStatus::PendingReview);

    $page = Livewire::test(DriverApplications::class)
        ->assertSee(__('admin.drivers.approve_item', ['item' => __('admin.details.values.government_id')]))
        ->assertSee(__('admin.drivers.approve_item', ['item' => __('admin.details.values.selfie')]))
        ->call('approveIdentity', $this->id->id)
        ->assertHasNoErrors()
        ->call('approveIdentity', $this->selfie->id)
        ->assertHasNoErrors();

    expect($this->id->refresh()->status)->toBe(VerificationStatus::Approved)
        ->and($this->selfie->refresh()->status)->toBe(VerificationStatus::Approved)
        ->and(AdminAction::query()->where('action', 'verification.approve')->where('admin_id', $reviewer->id)->count())->toBe(2);

    reviewCarOnPage($page)
        ->call('approve', $this->application->user_id)
        ->assertHasNoErrors();

    expect($this->application->refresh()->status)->toBe(DriverProfileStatus::Approved);
});

it('hides the identity buttons from staff who cannot decide verifications', function () {
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(DriverApplications::class)
        ->assertDontSee(__('admin.drivers.approve_item', ['item' => __('admin.details.values.government_id')]))
        ->call('approveIdentity', $this->id->id)
        ->assertForbidden();

    expect($this->id->refresh()->status)->toBe(VerificationStatus::Pending);
});

it('refuses to approve a verification that is not waiting', function () {
    actingAsAdmin(adminWithRole(AdminRole::Verification));

    $this->id->forceFill(['status' => VerificationStatus::ActionNeeded->value])->save();

    Livewire::test(DriverApplications::class)
        ->call('approveIdentity', $this->id->id)
        ->assertNotFound();
});
