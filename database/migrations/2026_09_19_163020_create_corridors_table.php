<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corridors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('name_ar', 150);
            $table->foreignUlid('origin_place_id')->constrained('places');
            $table->foreignUlid('destination_place_id')->constrained('places');
            $table->time('window_start');
            $table->time('window_end');
            $table->unsignedTinyInteger('days_mask');
            $table->string('status', 20)->default('healthy'); // healthy|driver_short|critical_gap|escort_armed
            $table->unsignedInteger('drivers_count')->default(0);
            $table->unsignedInteger('seekers_count')->default(0);
            $table->decimal('seat_fill_pct', 5, 2)->default(0);
            $table->timestamps();

            $table->index(['origin_place_id', 'destination_place_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corridors');
    }
};
