<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Livewire\Admin\AuditLog;
use Livewire\Livewire;

/**
 * The AUDIT LOG page (Phase 13): read-only, like the table it reads.
 */
it('shows what staff did, with the reason and what changed', function () {
    $super = actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    AdminAction::create([
        'admin_id' => $super->id,
        'action' => 'account.suspend',
        'entity_type' => 'User',
        'entity_id' => '01J00000000000000000000001',
        'old_value' => ['account_status' => 'active'],
        'new_value' => ['account_status' => 'suspended'],
        'reason' => 'Two reports about unsafe driving.',
        'ip_hash' => str_repeat('a', 64),
    ]);

    Livewire::test(AuditLog::class)
        ->assertSee(__('admin.audit.actions.account__suspend'))
        ->assertSee('Two reports about unsafe driving.')
        ->assertSee('"account_status":"suspended"')
        // 🔒 The hashes are for comparing, not reading.
        ->assertDontSee(str_repeat('a', 64));
});

it('narrows by area, by staff member, and by the record acted on', function () {
    $super = adminWithRole(AdminRole::SuperAdmin);
    $other = adminWithRole(AdminRole::SafetyLead);

    AdminAction::create(['admin_id' => $super->id, 'action' => 'settings.update', 'entity_type' => 'PlatformSetting', 'entity_id' => 'trip.wait_grace_seconds', 'reason' => 'Setting change reason']);
    AdminAction::create(['admin_id' => $other->id, 'action' => 'sos.acknowledge', 'entity_type' => 'SosEvent', 'entity_id' => '01J00000000000000000000002', 'reason' => 'Alert pick-up reason']);

    actingAsAdmin($super);

    Livewire::test(AuditLog::class)
        ->set('area', 'settings')
        ->assertSee('Setting change reason')
        ->assertDontSee('Alert pick-up reason')
        ->set('area', '')
        ->set('adminId', $other->id)
        ->assertSee('Alert pick-up reason')
        ->assertDontSee('Setting change reason')
        ->set('adminId', '')
        ->set('subject', 'trip.wait_grace_seconds')
        ->assertSee('Setting change reason')
        ->assertDontSee('Alert pick-up reason');
});

it('is closed to everybody without the audit permission', function (AdminRole $role) {
    actingAsAdmin(adminWithRole($role));

    Livewire::test(AuditLog::class)->assertForbidden();
})->with([AdminRole::Operations, AdminRole::SafetyLead, AdminRole::Verification, AdminRole::Finance, AdminRole::Support]);
