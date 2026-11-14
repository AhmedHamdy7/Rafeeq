<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'DELETE FROM trip_locations WHERE id NOT IN (
                SELECT keep_id FROM (
                    SELECT MIN(id) AS keep_id FROM trip_locations GROUP BY trip_session_id, recorded_at
                ) AS kept
            )'
        );

        Schema::table('trip_locations', function (Blueprint $table) {
            $table->unique(['trip_session_id', 'recorded_at']);
        });

        Schema::table('trip_locations', function (Blueprint $table) {
            $table->dropIndex(['trip_session_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('trip_locations', function (Blueprint $table) {
            $table->index(['trip_session_id', 'recorded_at']);
        });

        Schema::table('trip_locations', function (Blueprint $table) {
            $table->dropUnique(['trip_session_id', 'recorded_at']);
        });
    }
};
