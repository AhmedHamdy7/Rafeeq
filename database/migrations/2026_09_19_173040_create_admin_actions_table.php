<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔒 INSERT-only (Bible §6.2): in production, the app's DB user is
     * granted SELECT+INSERT only on this table, no UPDATE/DELETE.
     * `entity_id` is intentionally polymorphic with no FK — an admin action
     * can target any table in the system.
     */
    public function up(): void
    {
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('admin_id')->constrained('admin_users');
            $table->string('action', 50);
            $table->string('entity_type', 50);
            $table->ulid('entity_id')->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('reason', 255)->nullable(); // mandatory for sensitive actions — enforced at the Action layer
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index('admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
    }
};
