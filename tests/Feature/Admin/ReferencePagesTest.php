<?php

use App\Domains\Admin\Actions\UpdatePlatformSettingAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Livewire\Admin\Announcements;
use App\Livewire\Admin\Settings;
use Livewire\Livewire;

/**
 * Setting history (F04), the roles matrix (M03) and the push-events reference (Z03).
 */
it('shows a setting’s change history from the audit log', function () {
    $admin = adminWithRole(AdminRole::SuperAdmin);
    app(UpdatePlatformSettingAction::class)->update('auth.otp.ttl_seconds', 300, $admin, 'Codes expired too fast');
    actingAsAdmin($admin);

    Livewire::test(Settings::class)
        ->set('historyFor', 'auth.otp.ttl_seconds')
        ->assertViewHas('history', fn ($history) => $history->count() === 1)
        ->assertSee('Codes expired too fast');
});

it('draws the roles matrix on the team page', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $this->get(route('admin.team'))->assertOk()->assertSee(__('admin/team.matrix_title'))->assertSee('payment.view');
});

it('lists every notification type with its switch for Admins', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(Announcements::class)
        ->call('pick', 'events')
        ->assertSet('tab', 'events')
        ->assertSee('staff_message')
        ->assertSee(__('admin/communication.categories.safety'));
});
