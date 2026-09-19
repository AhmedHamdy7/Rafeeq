<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('event_type', 50); // otp_requested|login_success|login_failed|pin_lockout|token_reuse|pin_reset|device_revoked
            $table->string('risk_level', 10)->default('low'); // low|medium|high
            $table->json('metadata')->nullable(); // never a secret in here
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
