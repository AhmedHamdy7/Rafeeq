<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_offers', function (Blueprint $table) {
            $table->foreignUlid('replaces_booking_id')->nullable()->after('seat_request_id')->constrained('bookings')->nullOnDelete();
        });

        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->string('cancelled_by', 20)->nullable()->after('cancelled_reason');
        });

        DB::table('scheduled_trips')
            ->where('status', 'cancelled')
            ->update(['cancelled_by' => DB::raw("CASE WHEN cancelled_reason IN ('schedule_ended', 'account_deleted') THEN 'platform' ELSE 'driver' END")]);
    }

    public function down(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dropColumn('cancelled_by');
        });

        Schema::table('seat_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_booking_id');
        });
    }
};
