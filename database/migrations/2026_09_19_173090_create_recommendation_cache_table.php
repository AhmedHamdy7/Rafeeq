<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_cache', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('commute_offer_id')->constrained('commute_offers')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->json('reason_codes')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'commute_offer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_cache');
    }
};
