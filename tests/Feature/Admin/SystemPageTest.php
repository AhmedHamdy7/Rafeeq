<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Livewire\Admin\System;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

/**
 * The SYSTEM page: a fixed list of maintenance commands, super admin only, every run audited.
 */
it('runs a listed command, shows its output and records who ran it', function () {
    $super = actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(System::class)
        ->call('run', 'migrate_status')
        ->assertSee('php artisan migrate:status')
        ->assertSee('2026_10_05_090000_sync_admin_role_permissions');

    $row = AdminAction::query()->where('action', 'system.run')->sole();

    expect($row->admin_id)->toBe($super->id)
        ->and($row->new_value)->toBe(['command' => 'migrate:status', 'exit_code' => 0]);
});

it('runs migrate and clears the caches from the two quick actions', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    // Faked: a real optimize:clear would delete the compiled views other parallel test
    // processes are reading at that moment.
    Artisan::shouldReceive('call')->once()->with('migrate', Mockery::on(fn ($a) => $a['--force'] === true), Mockery::any())->andReturn(0);
    Artisan::shouldReceive('call')->once()->with('optimize:clear', Mockery::any(), Mockery::any())->andReturn(0);

    Livewire::test(System::class)
        ->call('run', 'migrate')
        ->assertSee('php artisan migrate --force')
        ->call('run', 'optimize_clear')
        ->assertSee('php artisan optimize:clear');

    expect(AdminAction::query()->where('action', 'system.run')->count())->toBe(2);
});

// 🔒 The whole point of a list: nothing outside it runs, whatever the browser sends.
it('refuses a command that is not on the list', function (string $key) {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(System::class)
        ->call('run', $key)
        ->assertNotFound();

    expect(AdminAction::query()->where('action', 'system.run')->exists())->toBeFalse();
})->with(['db:seed', 'migrate:fresh', 'migrate_fresh', 'tinker', 'env']);

it('is closed to everybody but the super admin', function (AdminRole $role) {
    actingAsAdmin(adminWithRole($role));

    Livewire::test(System::class)->assertForbidden();
})->with([AdminRole::Operations, AdminRole::SafetyLead, AdminRole::Verification, AdminRole::Finance, AdminRole::Support]);
