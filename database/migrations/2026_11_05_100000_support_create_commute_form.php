<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commute_offers', function (Blueprint $table) {
            $table->ulid('vehicle_id')->nullable()->change();
            $table->unsignedTinyInteger('seats_total')->nullable()->change();
        });

        Schema::table('match_scores', function (Blueprint $table) {
            $table->string('detour_tier', 16)->nullable()->after('detour_minutes');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('agreed_pickup_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('agreed_pickup_at');
        });

        Schema::table('match_scores', function (Blueprint $table) {
            $table->dropColumn('detour_tier');
        });

        Schema::table('commute_offers', function (Blueprint $table) {
            $table->ulid('vehicle_id')->nullable(false)->change();
            $table->unsignedTinyInteger('seats_total')->nullable(false)->change();
        });
    }
};
