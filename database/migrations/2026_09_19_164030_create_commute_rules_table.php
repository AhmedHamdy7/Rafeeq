<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commute_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commute_offer_id')->constrained('commute_offers')->cascadeOnDelete();
            $table->string('rule_key', 30); // nonsmoking|quiet|ac|nofood|luggage|front
            $table->boolean('rule_value')->default(true);
            $table->timestamps();

            $table->unique(['commute_offer_id', 'rule_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commute_rules');
    }
};
