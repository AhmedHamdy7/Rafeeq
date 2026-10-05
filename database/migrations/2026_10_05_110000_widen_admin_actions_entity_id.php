<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `admin_actions.entity_id` was a ULID column, which held for every subject until the
 * SETTINGS page (Phase 13): a platform setting is keyed by its name
 * (`auth.rate_limits.otp_verifications_per_challenge_per_minute`), which is not a ULID and
 * is longer than one. Without this, changing a setting could not be audited — and a change
 * to a rate limit or a retention period is exactly the kind of decision the audit trail is
 * for.
 *
 * Widened, not replaced: every existing value is a ULID and still fits, and the index on
 * `(entity_type, entity_id)` is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_actions', function (Blueprint $table) {
            $table->string('entity_id', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('admin_actions', function (Blueprint $table) {
            $table->ulid('entity_id')->nullable()->change();
        });
    }
};
