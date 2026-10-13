<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feature 01 v3 makes choosing a role its own step: Basic Profile → **Role** → Home, with
 * `ROLE_SELECTION` as a `next_step` the server can return and `POST /account/initial-role` to
 * answer it.
 *
 * Today the role is a REQUIRED FIELD of minimal profile setup, and the CHECK constraint added
 * with the registration flow refuses `profile_status = 'basic_complete'` unless
 * `registered_role` is present. So the two-step flow is not merely unimplemented — it is
 * currently impossible: the profile cannot be complete until the role is already known, which
 * means there is no state in which `ROLE_SELECTION` could ever be the right answer.
 *
 * 🔴 This therefore has to weaken a constraint, which is worth being honest about, because the
 * migration that added it argued at length that "complete always means complete". That argument
 * is kept — it is only re-scoped:
 *
 *   - `chk_users_basic_profile_complete` keeps guaranteeing that a complete profile has the
 *     IDENTITY facts: `full_name` and `gender`. Those are what every downstream consumer reads,
 *     and the original worry — a half-registered woman silently excluded from women-only
 *     matching because `gender` was NULL — is untouched by this change
 *   - the role moves to its own invariant below, instead of being dropped from all of them
 *
 * The net effect is that one constraint covering two unrelated facts becomes two constraints
 * covering one each, which is also why the first one could not simply be loosened and left.
 *
 * ⚠️ `registeredRole` stays ACCEPTED on `PUT /account/profile/basic`. A client that still sends
 * it — which is every client live today — gets the role recorded exactly as before and is routed
 * straight Home. Only a client that omits it sees `ROLE_SELECTION`. So this is additive at the
 * API edge despite being a schema change underneath, and the mobile team can adopt the new screen
 * on their own schedule rather than on deploy day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * v3 names this field. It is a timestamp rather than a boolean because the enrolment
             * date is the only way to tell a person who chose "passenger" on day one from a
             * person whose capability rows were added later by a support action.
             */
            $table->dateTime('initial_role_selected_at')->nullable()->after('registered_role');
        });

        /*
         * 🔴 Backfill BEFORE the constraints change, and before anything can read the column.
         *
         * Every existing account with a role chose it inside the profile step, which is what the
         * old flow required. Without this line all of them — real members and the whole demo data
         * set — would come back from a cold start with `initial_role_selected_at` NULL and be sent
         * to a role screen they already completed, having already picked that role.
         *
         * `updated_at` is deliberately left alone: this is a correction of the record, not
         * something the person did, and touching it would make every account look modified today
         * to anything that sorts or audits by it.
         */
        DB::table('users')
            ->whereNotNull('registered_role')
            ->update(['initial_role_selected_at' => DB::raw('COALESCE(created_at, NOW())')]);

        DB::statement('ALTER TABLE users DROP CONSTRAINT chk_users_basic_profile_complete');

        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT chk_users_basic_profile_complete CHECK (
                profile_status <> 'basic_complete'
                OR (gender IS NOT NULL AND full_name IS NOT NULL)
            )
        SQL);

        /*
         * The role's own invariant, replacing the half of the old one that was removed: the
         * timestamp cannot exist without the value it is the timestamp OF. That keeps
         * "role chosen" unambiguous from either direction, so neither
         * `initial_role_selected_at IS NOT NULL` nor `registered_role IS NOT NULL` can mislead a
         * reader who picks the wrong one.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT chk_users_initial_role_selected CHECK (
                initial_role_selected_at IS NULL OR registered_role IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT chk_users_initial_role_selected');
        DB::statement('ALTER TABLE users DROP CONSTRAINT chk_users_basic_profile_complete');

        /*
         * Restoring the original constraint can fail, and that is correct rather than unfortunate.
         * Any account that completed its profile under the new flow without yet choosing a role is
         * a row the old rule declares impossible. Rolling back with such rows present would need a
         * product decision — delete them, or guess a role for them — and a migration must not make
         * that choice silently at 3am.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT chk_users_basic_profile_complete CHECK (
                profile_status <> 'basic_complete'
                OR (gender IS NOT NULL AND registered_role IS NOT NULL AND full_name IS NOT NULL)
            )
        SQL);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('initial_role_selected_at');
        });
    }
};
