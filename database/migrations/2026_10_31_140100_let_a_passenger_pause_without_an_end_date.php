<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A passenger pause may have no end date (Feature 03 R-7, BOOK-32): "the passenger picks an end
 * date up to one month ahead on a calendar, or pauses with no end date".
 *
 * `to_date` becomes nullable; null means open-ended. The `chk_group_absences_range` CHECK stays as
 * it is — MySQL treats a CHECK that evaluates to UNKNOWN (a NULL `to_date`) as satisfied, so it
 * keeps refusing a range that ends before it starts and lets an open one through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_absences', function (Blueprint $table) {
            $table->date('to_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible without inventing an end date for every open pause.
    }
};
