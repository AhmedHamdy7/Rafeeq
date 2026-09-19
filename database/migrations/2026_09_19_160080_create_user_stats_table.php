<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_stats', function (Blueprint $table) {
            // 1:1 with users — the FK is the primary key, no separate id.
            $table->foreignUlid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('completed_trips_as_passenger')->default(0);
            $table->unsignedInteger('completed_trips_as_driver')->default(0);
            $table->decimal('avg_rating_as_passenger', 3, 2)->nullable();
            $table->decimal('avg_rating_as_driver', 3, 2)->nullable();
            $table->decimal('on_time_rate', 5, 2)->nullable();
            $table->decimal('cancellation_rate', 5, 2)->nullable();
            $table->unsignedInteger('no_show_count')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_stats');
    }
};
