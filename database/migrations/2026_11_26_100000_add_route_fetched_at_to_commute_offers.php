<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commute_offers', function (Blueprint $table) {
            $table->dateTime('route_fetched_at')->nullable()->after('route_duration_seconds');
        });

        DB::table('commute_offers')
            ->whereNotNull('route_polyline')
            ->update(['route_fetched_at' => DB::raw('published_at')]);
    }

    public function down(): void
    {
        Schema::table('commute_offers', function (Blueprint $table) {
            $table->dropColumn('route_fetched_at');
        });
    }
};
