<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature 01 v3 §8: staff may restrict the DRIVER capability only, or the whole account. An
 * account-level restriction shows Restricted Account and a route to support; a driver-only
 * restriction must preserve passenger access.
 *
 * 🔴 v3 proposes a new `account_restrictions` table. This adds two columns to
 * `account_suspensions` instead, because that table already is what v3 describes:
 *
 *   | v3 column        | here                                                      |
 *   |------------------|-----------------------------------------------------------|
 *   | user_id          | `user_id`                                                 |
 *   | reason_code      | `reason_code`                                             |
 *   | set_by_admin     | `suspended_by_admin_id` (plus `lifted_by_admin_id`)       |
 *   | created_at       | `suspended_at`                                            |
 *   | lifted_at        | `lifted_at`                                               |
 *   | status           | derived — `scopeInForce()` is `whereNull('lifted_at')`     |
 *   | scope            | ADDED HERE                                                |
 *   | public_message   | ADDED HERE                                                |
 *
 * A parallel table would have meant teaching the admin screens, `EnsureAccountIsActive`,
 * `SuspensionSummary` and `SuspendMemberAction` about a second place a person can be restricted —
 * and the first one of those that got missed would be a hole somebody walks through.
 *
 * `status` is deliberately NOT added. A stored status beside a nullable `lifted_at` is two
 * sources of truth for one fact, and they disagree the first time one is written without the
 * other — leaving a row that is simultaneously lifted and in force.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_suspensions', function (Blueprint $table) {
            /*
             * Defaulted to `account`, which is exactly what every existing row means: before this
             * migration the only possible restriction was total. So no backfill is needed and no
             * historical row changes meaning — the new value is the only new behaviour.
             */
            $table->string('scope', 20)->default('account')->after('user_id'); // account | driver

            /*
             * ⚠️ An optional override for what the member is shown, and the normal case is NULL.
             *
             * This repository already answers "what does the member see" better than a free-text
             * column does: `note` is internal, and the member is shown wording looked up from
             * `reason_code`, which exists in Arabic and in English and cannot leak a staff note.
             * A typed message is unlocalised by construction — whoever writes it picks one
             * language, and half the members read the other one.
             *
             * It is here because v3 asks for it, and it is nullable so that the localised path
             * stays the default rather than becoming the exception.
             */
            $table->string('public_message', 255)->nullable()->after('note');
        });

        /*
         * The driver-scope lookup that `LaunchRouter` and the driver middleware both run: "is
         * there an in-force restriction of this scope for this user". `(user_id, lifted_at)` is
         * already indexed, so this adds the scope dimension rather than duplicating it.
         */
        Schema::table('account_suspensions', function (Blueprint $table) {
            $table->index(['user_id', 'scope', 'lifted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('account_suspensions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'scope', 'lifted_at']);
            $table->dropColumn(['scope', 'public_message']);
        });
    }
};
