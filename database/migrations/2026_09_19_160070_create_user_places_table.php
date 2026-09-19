<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_places', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label', 20); // home | work | university | custom
            $table->string('display_name', 150)->nullable();
            // FK added once `places` exists (ERD group ④, migration order
            // puts user_places before places) — see 2026_09_19_161031_*.
            $table->ulid('place_id')->nullable();
            $table->geography('point', subtype: 'point', srid: 4326); // PRIVATE — never exposed exactly
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address', 255)->nullable();
            $table->boolean('is_default_origin')->default(false); // drives the Home screen
            $table->unsignedTinyInteger('blur_radius_meters')->default(200);
            $table->timestamps();

            $table->index('user_id');
            $table->index('place_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_places');
    }
};
