<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-2 (COM-15, decision C9): the driver says when he has to ARRIVE and RAFEEQ works out when he
 * leaves.
 *
 * A flag per weekday rather than a new mode on the commute, because a driver may arrive at 09:00
 * on four days and keep a fixed 07:30 departure on the fifth, and because every commute that
 * exists today was published with a departure the driver typed: those keep exactly the behaviour
 * they were published with until the driver saves a schedule without a departure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commute_weekday_schedules', function (Blueprint $table) {
            $table->boolean('departure_is_computed')->default(false)->after('promised_arrival_time');
        });
    }

    public function down(): void
    {
        Schema::table('commute_weekday_schedules', function (Blueprint $table) {
            $table->dropColumn('departure_is_computed');
        });
    }
};
