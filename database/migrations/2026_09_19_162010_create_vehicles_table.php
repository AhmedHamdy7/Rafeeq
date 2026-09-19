<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // driver_profiles has no `id` column — its PK is user_id.
            $table->foreignUlid('driver_profile_id')->constrained('driver_profiles', 'user_id')->cascadeOnDelete();
            $table->string('make', 50);
            $table->string('model', 50);
            $table->smallInteger('year');
            $table->string('colour', 30);
            $table->string('plate_number', 20);
            $table->string('plate_normalized', 20)->unique(); // no spaces, normalized digits — platform-wide unique
            $table->unsignedTinyInteger('seats'); // 2 to 8, caps offer seats
            $table->string('transmission', 20)->nullable();
            $table->string('fuel_type', 20)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('verification_status', 20)->default('pending'); // pending|approved|rejected|suspended
            $table->timestamps();

            $table->index('driver_profile_id');

            // MySQL/MariaDB have no native partial unique index, so "at most
            // one active vehicle per driver" (ERD §20, constraint #5) is
            // enforced the same way as users.phone_e164_active: a generated
            // column that collapses to NULL whenever is_active is false.
            $table->ulid('active_driver_profile_id')
                ->nullable()
                ->virtualAs('IF(is_active = 1, driver_profile_id, NULL)');
            $table->unique('active_driver_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
