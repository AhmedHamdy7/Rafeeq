<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_scores', function (Blueprint $table) {
            // 1:1 with users — the FK is the primary key, no separate id.
            $table->foreignUlid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0); // 0-100, INTERNAL — never exposed
            $table->string('public_tier', 20)->default('new'); // new|trusted|highly_trusted
            $table->json('components')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_scores');
    }
};
