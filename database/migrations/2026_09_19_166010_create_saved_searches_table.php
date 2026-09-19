<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 150)->nullable();
            $table->json('filters');
            $table->string('signature', 64); // hash of the filters — prevents duplicate saves
            $table->timestamps();

            $table->unique(['user_id', 'signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
