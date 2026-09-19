<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commute_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commute_offer_id')->unique()->constrained('commute_offers');
            $table->string('name', 150);
            $table->string('status', 20)->default('active'); // active|paused|disbanded
            $table->unsignedTinyInteger('min_commitment_days_per_week')->default(3);
            $table->decimal('on_time_pct', 5, 2)->default(0); // recomputed periodically
            $table->unsignedInteger('rides_together_count')->default(0);
            $table->unsignedTinyInteger('seats_open')->default(0); // denormalized
            $table->unsignedTinyInteger('notice_period_days')->default(7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commute_groups');
    }
};
