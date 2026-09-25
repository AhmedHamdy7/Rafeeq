<?php

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Livewire\Admin\DriverApplications;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The driver application queue.
 *
 * The other half of what was blocking the product: a driver could complete an
 * application and no surface existed to approve it, so no commute could ever be
 * published in production.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->reviewer = adminWithRole(AdminRole::Verification);

    // A complete application, submitted and waiting.
    $token = readyDriverApplicant();
    completeDriverApplication($token);

    test()->withToken($token)->postJson('/api/v1/driver/application/submit')->assertOk();

    $this->application = DriverProfile::query()
        ->where('status', DriverProfileStatus::PendingReview->value)
        ->sole();
});

it('shows the applications that are waiting', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(DriverApplications::class)
        ->assertOk()
        ->assertSee(__('admin.drivers.approve'));
});

it('approves a driver and their vehicle together', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(DriverApplications::class)
        ->call('approve', $this->application->user_id)
        ->assertHasNoErrors();

    expect($this->application->refresh()->status)->toBe(DriverProfileStatus::Approved)
        // Approving the driver approves the car they were reviewed with — otherwise
        // they are approved and still cannot publish, with nothing explaining why.
        ->and(Vehicle::sole()->verification_status->value)->toBe('approved');

    $entry = AdminAction::query()->where('action', 'driver.approve')->sole();

    expect($entry->admin_id)->toBe($this->reviewer->id)
        ->and($entry->old_value)->toBe(['status' => 'pending_review'])
        ->and($entry->new_value)->toBe(['status' => 'approved']);
});

it('rejects with a reason and records it', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(DriverApplications::class)
        ->call('reject', $this->application->user_id)
        ->assertHasErrors('reason')
        ->set('reason', 'The vehicle registration photo is too blurry to read the plate.')
        ->call('reject', $this->application->user_id)
        ->assertHasNoErrors();

    expect($this->application->refresh()->status)->toBe(DriverProfileStatus::Rejected);

    expect(AdminAction::query()->where('action', 'driver.reject')->sole()->reason)
        ->toContain('blurry');
});

/**
 * 🔴 Time passes while an application sits in a queue. Approving a driver whose licence
 * expired in the meantime would put somebody on the road who is not allowed to drive.
 */
it('refuses to approve a licence that expired while waiting', function () {
    actingAsAdmin($this->reviewer);

    $this->application->forceFill(['licence_expiry' => now()->subDay()->toDateString()])->save();

    Livewire::test(DriverApplications::class)
        ->call('approve', $this->application->user_id)
        // A readable refusal rather than a blank page: this is a real "no", not a bug.
        ->assertHasErrors('queue');

    expect($this->application->refresh()->status)->toBe(DriverProfileStatus::PendingReview);
});

it('lets support see nothing here at all', function () {
    $support = adminWithRole(AdminRole::Support);

    expect($support->can(AdminPermission::DriverView->value))->toBeFalse();

    actingAsAdmin($support);

    Livewire::test(DriverApplications::class)->assertForbidden();
});

it('lets operations decide, since approving drivers is their job', function () {
    $operations = adminWithRole(AdminRole::Operations);

    expect($operations->can(AdminPermission::DriverDecide->value))->toBeTrue()
        // ...but not open an identity document.
        ->and($operations->can(AdminPermission::VerificationViewDocument->value))->toBeFalse();

    actingAsAdmin($operations);

    Livewire::test(DriverApplications::class)
        ->call('approve', $this->application->user_id)
        ->assertHasNoErrors();

    expect($this->application->refresh()->status)->toBe(DriverProfileStatus::Approved);
});

it('refuses to decide an application somebody else already decided', function () {
    actingAsAdmin($this->reviewer);

    $page = Livewire::test(DriverApplications::class);

    $this->application->forceFill(['status' => DriverProfileStatus::Approved->value])->save();

    $page->call('approve', $this->application->user_id)->assertNotFound();
});

/**
 * 🔒 Finance touches money and nothing else. Approving payouts is not a reason to be
 * able to put a driver on the road.
 */
it('keeps finance out of both queues', function () {
    $finance = adminWithRole(AdminRole::Finance);

    expect($finance->can(AdminPermission::DriverDecide->value))->toBeFalse()
        ->and($finance->can(AdminPermission::VerificationDecide->value))->toBeFalse()
        ->and($finance->can(AdminPermission::PayoutApprove->value))->toBeTrue();
});

/**
 * Only Super Admin can mint another admin. Nothing else in the dashboard should be able
 * to grant itself more access.
 */
it('reserves creating admins to the super admin', function () {
    foreach ([AdminRole::Operations, AdminRole::Verification, AdminRole::Finance, AdminRole::Support, AdminRole::SafetyLead] as $role) {
        expect(adminWithRole($role)->can(AdminPermission::AdminManage->value))
            ->toBeFalse("{$role->value} can create admins.");
    }

    expect(adminWithRole(AdminRole::SuperAdmin)->can(AdminPermission::AdminManage->value))->toBeTrue();
});
