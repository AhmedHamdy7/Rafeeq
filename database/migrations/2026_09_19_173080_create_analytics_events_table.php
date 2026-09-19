<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `user_id` is SET NULL on delete (ERD §18) — deleting a user must never
     * be blocked by analytics history, and no personal data belongs in
     * `metadata` (Bible §4, group ⑭).
     */
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_id', 100)->nullable();
            $table->string('event_name', 100);
            $table->string('entity_type', 50)->nullable();
            $table->ulid('entity_id')->nullable();
            $table->json('metadata')->nullable(); // NO personal data, ever
            $table->dateTime('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('event_name');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
