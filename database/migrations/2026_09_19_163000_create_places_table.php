<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('name_ar', 150);
            $table->string('type', 30); // compound_gate|street|landmark|station|campus|office
            $table->geography('point', subtype: 'point', srid: 4326);
            $table->decimal('lat', 10, 7); // duplicated for fast, function-free filtering
            $table->decimal('lng', 10, 7);
            $table->string('city', 80)->nullable();
            $table->string('district', 80)->nullable();
            $table->string('google_place_id', 120)->nullable();
            $table->boolean('is_public')->default(true); // public spot vs private home
            $table->unsignedInteger('usage_count')->default(0); // ranking
            $table->timestamps();

            $table->spatialIndex('point');
            $table->index(['city', 'district']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
