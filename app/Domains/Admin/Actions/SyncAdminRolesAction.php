<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\AdminRole;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writes the role → permission map from {@see AdminRole::permissions()} into Spatie's
 * tables.
 *
 * 🔴 The map in code is the authority, and this is what makes that true. The
 * alternative — roles granted by hand in a dashboard — means the answer to "what can
 * Operations do?" lives only in production, differs from staging, cannot be reviewed in
 * a pull request, and quietly accumulates whatever anyone granted in a hurry. Here it
 * is a diff.
 *
 * Idempotent, and safe to run on every deploy: permissions are created if missing and
 * each role's set is SYNCED, so a permission removed from the map is removed from the
 * role. That direction matters more than the other one — a capability taken away in
 * code must actually be taken away.
 *
 * Roles are never deleted, because an admin could still be assigned one and dropping it
 * would silently strip a real person's access; an obsolete role is emptied instead, so
 * it stops granting anything while remaining visible.
 */
final readonly class SyncAdminRolesAction
{
    /**
     * @return array{permissions: int, roles: int}
     */
    public function execute(): array
    {
        return DB::transaction(function (): array {
            foreach (AdminPermission::cases() as $permission) {
                Permission::findOrCreate($permission->value, 'admin');
            }

            foreach (AdminRole::cases() as $role) {
                $model = Role::findOrCreate($role->value, 'admin');

                $model->syncPermissions(
                    array_map(fn (AdminPermission $p) => $p->value, $role->permissions())
                );
            }

            // Spatie caches the whole map; without this the process that just wrote it
            // keeps answering from the version it read before.
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return [
                'permissions' => count(AdminPermission::cases()),
                'roles' => count(AdminRole::cases()),
            ];
        });
    }
}
