<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 created `users.gender` and `users.registered_role` as NOT NULL.
 * The Chapter 2 registration flow proves that cannot hold: the user row is
 * created the moment an OTP is verified (Chapter 2 §23.2 returns `user.id`
 * there), while both of those fields are only collected afterwards, in
 * minimal profile setup (§17). The Engineering Bible's own walkthrough
 * agrees — §1.3 writes the row with neither field, and §1.5 fills them in.
 * Chapter 2 §20.1 likewise marks `gender` "Policy-based", not required.
 *
 * The rejected alternative was to insert a placeholder gender. That is the
 * dangerous option, not the safe one: `prefer_not_to_say` is a real, valid
 * answer, so a half-registered woman would be silently excluded from
 * women-only matching (Bible §15.2) with nothing to distinguish "hasn't
 * answered yet" from "chose not to say".
 *
 * NULL means "not answered yet", and the CHECK constraint makes that state
 * un-leakable: no row can claim `profile_status = 'basic_complete'` while
 * any of the three identity fields is still unknown. So every consumer
 * downstream may keep treating a complete profile as fully populated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('gender', 20)->nullable()->change();
            $table->string('registered_role', 20)->nullable()->change();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT chk_users_basic_profile_complete CHECK (
                profile_status <> 'basic_complete'
                OR (gender IS NOT NULL AND registered_role IS NOT NULL AND full_name IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT chk_users_basic_profile_complete');

        Schema::table('users', function (Blueprint $table) {
            $table->string('gender', 20)->nullable(false)->change();
            $table->string('registered_role', 20)->nullable(false)->change();
        });
    }
};
