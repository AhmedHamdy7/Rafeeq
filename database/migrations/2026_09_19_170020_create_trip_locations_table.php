<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔴 The highest-volume, highest-risk table in the system (ERD §22:
     * ~100M rows/year). Live location goes to Redis first and broadcasts
     * immediately; a job batches inserts here every 30s (Bible §12,
     * "why not straight to MySQL"). No `updated_at` — a location point is
     * never revised, only ever inserted and eventually purged.
     */
    public function up(): void
    {
        Schema::create('trip_locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('trip_session_id')->constrained('trip_sessions')->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->smallInteger('accuracy_meters')->nullable();
            $table->smallInteger('speed_kmh')->nullable();
            $table->dateTime('recorded_at'); // DEVICE time, not receive time
            $table->date('purge_after')->nullable(); // 90-day retention (§23.4)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['trip_session_id', 'recorded_at']);
            $table->index('purge_after');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_locations');
    }
};
