<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a driver called off a run already on the road, as a category (TRIP-22, F-13).
 *
 * `breakdown`, `accident`, `emergency` or `other` (`ActiveTripCancelReason`). On the session rather
 * than the day because it describes the RUN that was stopped: the day row also carries a cancelled
 * reason for "cancel today", which happens before any run exists and has no category.
 *
 * Nullable: every run that ended any other way — and every one cancelled before this existed — has
 * no category to record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_sessions', function (Blueprint $table) {
            $table->string('cancel_reason', 20)->nullable()->after('current_status');
        });
    }

    public function down(): void
    {
        Schema::table('trip_sessions', function (Blueprint $table) {
            $table->dropColumn('cancel_reason');
        });
    }
};
