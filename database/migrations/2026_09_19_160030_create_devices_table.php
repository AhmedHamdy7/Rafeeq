<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_public_id', 64); // app-generated, not an advertising id
            $table->string('platform', 20); // android | ios
            $table->string('device_model', 80)->nullable();
            $table->string('os_version', 30)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->text('push_token')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->boolean('has_local_pin')->default(false); // fact only — never the PIN value
            $table->boolean('biometric_enabled')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Composite, not a bare UNIQUE(device_public_id): the "use another
            // account" screen lets several accounts share one physical device.
            $table->unique(['user_id', 'device_public_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
