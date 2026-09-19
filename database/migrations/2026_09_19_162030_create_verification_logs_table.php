<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔒 INSERT-only (Bible §4, group ③): the permanent audit trail behind
     * every verification decision. No `updated_at` — a row here is never
     * updated, only ever created (enforced app-side by IsAppendOnly, and in
     * production at the database-grant level per Bible §6.2).
     */
    public function up(): void
    {
        Schema::create('verification_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('entity_type', 50); // e.g. "driver_profile" — no real FK, polymorphic by convention
            $table->ulid('entity_id');
            $table->foreignUlid('admin_id')->constrained('admin_users');
            $table->string('action', 30); // approve|reject|request_info|suspend
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('reason', 255)->nullable(); // mandatory on reject, enforced in the Action layer
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_logs');
    }
};
