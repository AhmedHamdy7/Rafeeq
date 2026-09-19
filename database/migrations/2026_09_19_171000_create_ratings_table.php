<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔴 Double-blind (Bible §9, pitfall #26): `visible_at` is NULL until
     * BOTH parties have rated (or 7 days pass). Every query for someone
     * else's ratings must filter `whereNotNull('visible_at')` — never trust
     * the UI to hide it.
     */
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id')->constrained('bookings'); // RESTRICT — permanent (soft-deleted for audit)
            $table->foreignUlid('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('reviewed_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('direction', 30); // passenger_to_driver | driver_to_passenger
            $table->unsignedTinyInteger('stars');
            $table->text('comment')->nullable(); // passed through abuse filtering at the Action layer
            $table->dateTime('visible_at')->nullable(); // NULL = hidden
            $table->dateTime('edit_deadline_at')->nullable();
            $table->dateTime('edited_at')->nullable();
            $table->string('moderation_status', 20)->default('clean'); // clean|flagged|hidden
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['booking_id', 'reviewer_user_id']);
            $table->index(['reviewed_user_id', 'visible_at']);
        });

        DB::statement('ALTER TABLE ratings ADD CONSTRAINT chk_ratings_stars_range CHECK (stars BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
