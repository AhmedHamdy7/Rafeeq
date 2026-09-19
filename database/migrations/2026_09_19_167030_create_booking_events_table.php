<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔒 INSERT-only — "what exactly happened" when a booking is disputed
     * (Bible §4, group ⑦).
     */
    public function up(): void
    {
        Schema::create('booking_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('event_type', 30); // created|confirmed|cancelled|completed|no_show|disputed
            $table->string('actor_type', 20); // passenger|driver|system|admin
            $table->ulid('actor_id')->nullable(); // null when actor_type = system
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_events');
    }
};
