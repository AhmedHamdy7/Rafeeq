<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The webhook rule (Bible §4, group ⑨): (1) record raw immediately,
     * (2) verify signature, (3) skip if event_id seen before, (4) hand off
     * to a queue job, (5) return 200 fast. Never process inside the request.
     */
    public function up(): void
    {
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 20);
            $table->string('event_id', 120); // 🔴 replay guard — Paymob resends the same event
            $table->string('event_type', 60)->nullable();
            $table->json('payload'); // raw, exactly as received
            $table->boolean('signature_valid'); // verified BEFORE any processing
            $table->dateTime('processed_at')->nullable(); // null = not yet processed
            $table->string('processing_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};
