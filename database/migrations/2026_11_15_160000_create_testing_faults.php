<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testing_faults', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('phone_e164', 20)->nullable()->index();
            $table->string('path', 191);
            $table->string('method', 10)->nullable();
            $table->string('mode', 10);
            $table->unsignedInteger('delay_ms')->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('code', 64)->nullable();
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->unsignedTinyInteger('remaining');
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testing_faults');
    }
};
