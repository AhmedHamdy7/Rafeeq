<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commute_locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commute_offer_id')->constrained('commute_offers')->cascadeOnDelete();
            $table->foreignUlid('place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->string('type', 20); // origin|pickup|dropoff|destination
            $table->geography('point', subtype: 'point', srid: 4326);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address', 255)->nullable();
            $table->smallInteger('sequence'); // origin=0, destination=999
            $table->boolean('is_exact')->default(true); // false = approximate area only
            $table->timestamps();

            $table->spatialIndex('point');
            $table->index(['commute_offer_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commute_locations');
    }
};
