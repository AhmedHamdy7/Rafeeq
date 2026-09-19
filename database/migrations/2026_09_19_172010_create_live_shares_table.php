<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pitfall #29: the share link's security is entirely the token's
     * entropy — `token_hash` must come from ≥32 random bytes, never a
     * guessable id.
     */
    public function up(): void
    {
        Schema::create('live_shares', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('trip_session_id')->constrained('trip_sessions')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->foreignUlid('shared_with_contact_id')->nullable()->constrained('emergency_contacts')->nullOnDelete();
            $table->dateTime('expires_at'); // ends with the trip + a short grace period
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->dateTime('last_viewed_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_shares');
    }
};
