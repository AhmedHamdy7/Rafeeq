<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // No FK to users on purpose (Bible §4, group 1): a challenge is
            // created before we know whether this phone has an account yet.
            $table->string('phone_e164', 20);
            $table->string('purpose', 30); // authentication|pin_reset|phone_change|high_risk_action
            $table->string('code_hash', 255);
            // dateTime, not timestamp: a required TIMESTAMP with no default
            // hits MariaDB/MySQL's legacy implicit-default quirk (only the
            // first TIMESTAMP column in a table gets an implicit default;
            // later ones fail under NO_ZERO_DATE). dateTime has no such trap.
            $table->dateTime('expires_at');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->unsignedTinyInteger('resend_count')->default(0);
            $table->string('status', 20)->default('pending'); // pending|verified|expired|blocked
            $table->string('device_fingerprint_hash', 64)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('phone_e164');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
