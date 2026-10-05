<?php

use App\Domains\Admin\Actions\SyncAdminRolesAction;
use Illuminate\Database\Migrations\Migration;

/**
 * Writes the roles' permissions again, from `AdminRole::permissions()`.
 *
 * 🔴 A data migration, because nothing else would reach an instance that is already
 * running. Role permissions are stored rows (Spatie), written only when `admin:create`
 * or the seeder runs. Phase 13 added `trip.view` and granted it to operations and the
 * safety lead — and on a deployment that already has its admins, no command anybody runs
 * routinely would have written it. The result would have been the live board refusing
 * everybody, super admin included, with nothing in the logs explaining why.
 *
 * Migrations are the one step every deployment already runs (DEPLOYMENT.md, the Railway
 * and Oracle guides). The Action is idempotent and is the same one the application uses,
 * so this cannot write a second, different idea of what each role may do.
 *
 * The next permission change needs the same treatment: a new migration that calls this
 * Action again.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(SyncAdminRolesAction::class)->execute();
    }

    /**
     * Nothing to undo. Rolling the code back rolls `AdminRole::permissions()` back with it,
     * and the next sync writes that version; deleting permission rows here would take
     * access away from roles that held it before this migration ran.
     */
    public function down(): void {}
};
