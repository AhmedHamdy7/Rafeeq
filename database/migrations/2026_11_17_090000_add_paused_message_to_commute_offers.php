<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C04 · what staff tell the driver when they pause her commute (`pausedReason: by_staff`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commute_offers', function (Blueprint $table) {
            $table->string('paused_message', 500)->nullable()->after('paused_reason');
        });
    }

    public function down(): void
    {
        Schema::table('commute_offers', function (Blueprint $table) {
            $table->dropColumn('paused_message');
        });
    }
};
