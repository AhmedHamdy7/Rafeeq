<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_scores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('demand_signature', 64); // hash of the search parameters
            $table->foreignUlid('scheduled_trip_id')->constrained('scheduled_trips')->cascadeOnDelete();
            $table->unsignedTinyInteger('total'); // 0-100
            $table->unsignedTinyInteger('overlap_score'); // max 30
            $table->unsignedTinyInteger('schedule_score'); // max 25
            $table->unsignedTinyInteger('detour_score'); // max 15
            $table->unsignedTinyInteger('audience_score'); // max 10
            $table->unsignedTinyInteger('comfort_score'); // max 10
            $table->unsignedTinyInteger('price_score'); // max 5
            $table->unsignedTinyInteger('reliability_score'); // max 5
            $table->decimal('walk_minutes', 4, 1)->nullable();
            $table->decimal('detour_minutes', 4, 1)->nullable();
            $table->dateTime('expires_at'); // 1 hour
            $table->timestamps();

            $table->index('demand_signature');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_scores');
    }
};
