<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `user_places.place_id` was created without a constraint in ERD group
     * ① (2026_09_19_160070) because `places` (ERD group ④) didn't exist yet
     * — the ERD's own migration order puts user_places before places. The
     * column is attached here now that the target table exists.
     */
    public function up(): void
    {
        Schema::table('user_places', function (Blueprint $table) {
            $table->foreign('place_id')->references('id')->on('places')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_places', function (Blueprint $table) {
            $table->dropForeign(['place_id']);
        });
    }
};
