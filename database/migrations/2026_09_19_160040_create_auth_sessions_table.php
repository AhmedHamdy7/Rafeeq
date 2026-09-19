<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named `auth_sessions`, not `sessions`: the ERD's `sessions` table
     * (mobile API refresh-token rotation) collides in name with Laravel's
     * own built-in `sessions` table (HTTP session storage, still needed for
     * the Livewire admin dashboard per decision D4). Same columns as
     * documented, different table name — approved deviation, see
     * RAFEEQ_PROGRESS.md.
     */
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('refresh_token_hash', 64);
            $table->ulid('token_family_id');
            $table->ulid('previous_session_id')->nullable();
            // dateTime, not timestamp — see the note in the otp_challenges
            // migration on MariaDB's implicit-default quirk for required
            // TIMESTAMP columns.
            $table->dateTime('access_expires_at');
            $table->dateTime('refresh_expires_at');
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 50)->nullable(); // user_logout|token_reuse|admin|stolen_device
            $table->timestamps();

            $table->index('refresh_token_hash');
            $table->index('token_family_id');
            $table->foreign('previous_session_id')->references('id')->on('auth_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
    }
};
