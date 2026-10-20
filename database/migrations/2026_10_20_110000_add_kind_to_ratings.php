<?php

use App\Domains\Rating\Enums\RatingKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback on a seat that was cancelled, beside the review of a journey that happened
 * (Feature 03 v5).
 *
 * > *"Passenger affected by **canceled confirmed booking** may leave rating/comment even if no ride
 * > occurred. Display as **Canceled Booking Feedback**, distinct from completed-ride review;
 * > visible immediately."*
 *
 * 🔴 One column rather than a second table, because everything else about the two is identical: the
 * same stars, the same comment, the same moderation, the same reporting route for a driver who
 * thinks a comment is unfair. What differs is three behaviours, and all three read off this column
 * — see {@see RatingKind}. A separate table would have duplicated the moderation and report
 * machinery and then slowly disagreed with it.
 *
 * Every existing row is a review of a completed journey, which is the default, so nothing is
 * backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->string('kind', 20)
                ->default(RatingKind::CompletedRide->value)
                ->after('direction');

            // Read together whenever a profile separates the two lists, which is every time one is
            // shown.
            $table->index(['reviewed_user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropIndex(['reviewed_user_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
