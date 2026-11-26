<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_suggestions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 20);
            $table->string('label', 255)->nullable();
            $table->string('label_ar', 255)->nullable();
            $table->string('search_text', 1000)->default('');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('google_place_id', 255)->nullable()->unique();
            $table->string('area_id', 255)->nullable();
            $table->foreign('area_id')->references('google_place_id')->on('areas')->nullOnDelete();
            $table->unsignedTinyInteger('roles')->default(0);
            $table->unsignedInteger('uses')->default(0);
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('coords_fetched_at')->nullable();
            $table->timestamps();

            $table->index(['lat', 'lng']);
            $table->index(['kind', 'coords_fetched_at']);
        });

        Schema::create('place_pin_picks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label', 255)->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('area_id', 255)->nullable();
            $table->foreign('area_id')->references('google_place_id')->on('areas')->nullOnDelete();
            $table->unsignedTinyInteger('roles')->default(0);
            $table->unsignedInteger('uses')->default(0);
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['lat', 'lng']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_pin_picks');
        Schema::dropIfExists('place_suggestions');
    }
};
