<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔴 Pitfall #27: a block must be checked in BOTH directions in matching
     * — checking only `blocker_user_id` misses the case where the other
     * party is the one who blocked you.
     */
    public function up(): void
    {
        Schema::create('blocked_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('blocker_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['blocker_user_id', 'blocked_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_users');
    }
};
