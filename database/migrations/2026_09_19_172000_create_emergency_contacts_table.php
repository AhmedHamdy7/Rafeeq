<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone_e164', 20);
            $table->string('relationship', 50)->nullable();
            $table->boolean('auto_share_trips')->default(false); // sees every trip automatically
            $table->boolean('is_guardian')->default(false); // elevated access during an emergency
            $table->dateTime('verified_at')->nullable(); // confirmed the number actually receives messages
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
    }
};
