<?php

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * 🔴 A permission added in code reaches an instance that is already running.
 *
 * Role permissions are stored rows, so adding `trip.view` to `AdminRole::permissions()` changes
 * nothing for admins who already exist until something writes it. That something is the data
 * migration, because migrating is the step every deployment runs.
 */
it('grants a permission added in code to admins who already existed', function () {
    $lead = adminWithRole(AdminRole::SafetyLead);

    // The state of an instance deployed before the permission existed.
    DB::table('permissions')->where('name', AdminPermission::TripView->value)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(AdminUser::query()->whereKey($lead->id)->sole()->can(AdminPermission::TripView->value))->toBeFalse();

    $migration = require database_path('migrations/2026_10_05_090000_sync_admin_role_permissions.php');
    $migration->up();

    expect(AdminUser::query()->whereKey($lead->id)->sole()->can(AdminPermission::TripView->value))->toBeTrue();
});
