<?php

use App\Domains\Admin\Actions\SyncAdminRolesAction;
use Illuminate\Database\Migrations\Migration;

/**
 * Writes `safety.view_evidence` (Phase 13) onto the roles of an instance that is already
 * running. See 2026_10_05_090000_sync_admin_role_permissions for why every new permission
 * needs one of these.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(SyncAdminRolesAction::class)->execute();
    }

    public function down(): void {}
};
