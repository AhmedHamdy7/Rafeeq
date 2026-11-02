<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRIPS-17: her own note to her driver about where she waits ("I'll wait by the pharmacy"). On the
 * booking because it belongs to that seat, and nowhere else: it is not a chat message and has no
 * thread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('pickup_note', 200)->nullable()->after('outcome_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('pickup_note');
        });
    }
};
