<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_offers', function (Blueprint $table) {
            $table->unique(['seat_request_id', 'release_event_id', 'scheduled_trip_id'], 'seat_offers_one_per_release_day');
        });

        Schema::table('seat_offers', function (Blueprint $table) {
            $table->dropUnique('seat_offers_one_per_release');
        });
    }

    public function down(): void
    {
        Schema::table('seat_offers', function (Blueprint $table) {
            $table->unique(['seat_request_id', 'release_event_id'], 'seat_offers_one_per_release');
        });

        Schema::table('seat_offers', function (Blueprint $table) {
            $table->dropUnique('seat_offers_one_per_release_day');
        });
    }
};
