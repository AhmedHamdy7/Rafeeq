<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔒 NEVER DELETE (Bible §4, group ⑫) — real evidence for a real
     * investigation.
     */
    public function up(): void
    {
        Schema::create('safety_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 30); // sos|discreet_alert|live_share_started|incident_created|escort_armed|admin_intervention
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('trip_session_id')->nullable()->constrained('trip_sessions')->nullOnDelete();
            $table->foreignUlid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('severity', 20); // low|medium|high|critical
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at'); // event time, not insert time
            $table->timestamps(); // updates allowed (e.g. an admin note) — only deletion is forbidden

            $table->index(['user_id', 'type']);
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_events');
    }
};
