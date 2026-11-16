<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Livewire\Admin\Directory\CommuteDetail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Staff pausing a commute from the dashboard (C04): the driver reads why, and only staff resume.
 */
beforeEach(function () {
    Storage::fake('documents');
    fakeOtpSender();

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);
    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();
    $this->driver = User::query()->where('phone_e164', '+201012345678')->sole();
});

it('pauses a commute with a message the driver reads, and only staff resume it', function () {
    actingAsAdmin(adminWithRole(AdminRole::Operations));
    $commute = CommuteOffer::query()->findOrFail($this->commuteId);

    Livewire::test(CommuteDetail::class, ['commute' => $commute])
        ->call('startPause')
        ->assertSee(__('admin/records.commute.impact_seats'))
        ->set('reason', 'Repeated reports about the route')
        ->set('pauseMessage', 'We paused this commute while we look into reports about the route.')
        ->call('pause')
        ->assertHasNoErrors()
        ->assertSet('done', __('admin/records.commute.paused_done'));

    $commute->refresh();

    expect($commute->status)->toBe(CommuteOfferStatus::Paused)
        ->and($commute->paused_reason)->toBe(CommutePausedReason::ByStaff)
        ->and(Notification::query()->where('user_id', $this->driver->id)->where('body', 'We paused this commute while we look into reports about the route.')->exists())->toBeTrue()
        ->and(AdminAction::query()->where('action', 'commute.pause_by_staff')->value('reason'))->toBe('Repeated reports about the route');

    test()->withToken($this->driverToken)->getJson("/api/v1/commutes/{$this->commuteId}")
        ->assertOk()
        ->assertJsonPath('data.pausedReason', 'by_staff')
        ->assertJsonPath('data.pausedMessage', 'We paused this commute while we look into reports about the route.');

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/resume")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMUTE_INVALID_TRANSITION')
        ->assertJsonPath('error.fields.pausedReason.0', 'by_staff');

    Livewire::test(CommuteDetail::class, ['commute' => $commute])
        ->call('startResume')
        ->set('reason', 'Reports checked, nothing found')
        ->call('resume')
        ->assertHasNoErrors()
        ->assertSet('done', __('admin/records.commute.resumed'));

    expect($commute->refresh()->status)->toBe(CommuteOfferStatus::Published)
        ->and($commute->paused_message)->toBeNull();
});

it('keeps the pause from staff without the commute desk', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    $this->get(route('admin.commutes.show', $this->commuteId))->assertForbidden();
});

it('needs a message for the driver', function () {
    actingAsAdmin(adminWithRole(AdminRole::Operations));

    Livewire::test(CommuteDetail::class, ['commute' => CommuteOffer::query()->findOrFail($this->commuteId)])
        ->call('startPause')
        ->set('reason', 'Repeated reports about the route')
        ->call('pause')
        ->assertHasErrors('pauseMessage');

    expect(CommuteOffer::query()->findOrFail($this->commuteId)->status)->toBe(CommuteOfferStatus::Published);
});
