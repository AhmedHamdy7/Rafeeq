<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per time staff put an account on hold, and how it ended (Phase 13, MEMBERS).
 *
 * `users.account_status` says WHETHER somebody is suspended; this says why, by whom, under
 * which case reference, and by when the platform promised to look again. The
 * restricted-account screen (35) shows exactly two of those — the reference and the
 * expected update — and until this table existed neither had anywhere to come from.
 *
 * A row is never deleted: lifting a suspension fills `lifted_*` and leaves the rest. A
 * second suspension of the same person a month later is a second row, so the history of
 * how often somebody has been stopped is the table itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_suspensions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users');
            // What the member quotes when they contact the safety team. Random, not
            // sequential: a sequence would tell anybody holding one how many accounts the
            // platform has suspended.
            $table->string('case_number', 20)->unique();
            $table->string('reason_code', 30); // safety_report|identity_check|payment_issue|policy_breach
            $table->string('note', 255); // INTERNAL — the member is shown the reason code's wording, never this
            $table->foreignUlid('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->foreignUlid('suspended_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->dateTime('suspended_at');
            // "Expected update within 24h" — written when suspended, like an incident's SLA,
            // so the promise cannot be moved afterwards by changing a setting.
            $table->dateTime('review_due_at');
            $table->dateTime('lifted_at')->nullable();
            $table->foreignUlid('lifted_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('lift_note', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'lifted_at']);
            $table->index('incident_id');
            $table->index('suspended_by_admin_id');
            $table->index('lifted_by_admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_suspensions');
    }
};
