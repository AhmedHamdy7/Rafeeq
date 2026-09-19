<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commute_demand_id')->constrained('commute_demands')->cascadeOnDelete();
            $table->foreignUlid('commute_offer_id')->constrained('commute_offers')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('clicked_at')->nullable();
            $table->timestamps();

            // ERD §20 constraint #13 — never notify the same offer twice.
            $table->unique(['commute_demand_id', 'commute_offer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_notifications');
    }
};
