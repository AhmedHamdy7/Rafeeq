<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('name_ar', 150);
            $table->string('type', 20); // work | university | school
            $table->string('email_domain', 100)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('district', 80)->nullable();
            // Nullable, so no SPATIAL INDEX (MariaDB/MySQL require NOT NULL for
            // spatial-indexed columns) — not on the ERD §21 critical-index list.
            $table->geography('location_point', subtype: 'point', srid: 4326)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index('email_domain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
