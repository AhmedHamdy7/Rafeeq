<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escort_windows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('corridor_id')->constrained('corridors')->cascadeOnDelete();
            $table->dateTime('starts_at'); // auto-arms at 9pm
            $table->dateTime('ends_at'); // 5am
            $table->unsignedInteger('trips_covered')->default(0);
            $table->foreignUlid('armed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->boolean('is_auto')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escort_windows');
    }
};
