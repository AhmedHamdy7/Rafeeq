<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('rating_id')->constrained('ratings')->cascadeOnDelete();
            $table->string('tag', 30); // safe_driving|on_time|clean_car|great_company|comfortable|would_ride_again
            $table->timestamps();

            $table->unique(['rating_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_tags');
    }
};
