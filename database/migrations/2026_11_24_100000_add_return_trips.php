<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commute_offers', function (Blueprint $table): void {
            $table->ulid('return_of_commute_id')->nullable()->after('direction');
            $table->foreign('return_of_commute_id')->references('id')->on('commute_offers')->nullOnDelete();
        });

        Schema::table('commute_weekday_schedules', function (Blueprint $table): void {
            $table->time('promised_departure_time')->nullable()->after('promised_arrival_time');
            $table->time('estimated_arrival_time')->nullable()->after('promised_departure_time');
        });

        Schema::table('scheduled_trips', function (Blueprint $table): void {
            $table->timestamp('promised_departure_at')->nullable()->after('promised_arrival_at');
            $table->timestamp('estimated_arrival_at')->nullable()->after('promised_departure_at');
        });

        Schema::table('commute_demands', function (Blueprint $table): void {
            $table->string('direction', 10)->default('going')->after('commute_type');
            $table->time('leave_at')->nullable()->after('preferred_arrival_end');
            $table->ulid('paired_demand_id')->nullable()->after('leave_at');
            $table->index('paired_demand_id');
        });

        DB::table('commute_offers')->where('direction', 'to_work')->update(['direction' => 'going']);
        DB::table('commute_offers')->where('direction', 'to_home')->update(['direction' => 'return']);

        $returns = DB::table('commute_offers')->where('direction', 'return')->pluck('id');

        foreach ($returns as $offerId) {
            DB::table('commute_weekday_schedules')->where('commute_offer_id', $offerId)->update([
                'promised_departure_time' => DB::raw('departure_time'),
                'promised_arrival_time' => null,
                'departure_is_computed' => false,
            ]);

            DB::table('scheduled_trips')
                ->where('commute_offer_id', $offerId)
                ->update([
                    'promised_departure_at' => DB::raw('departure_at'),
                    'estimated_arrival_at' => DB::raw('COALESCE(promised_arrival_at, departure_at)'),
                ]);

            DB::table('scheduled_trips')
                ->where('commute_offer_id', $offerId)
                ->where('status', 'scheduled')
                ->update(['promised_arrival_at' => null]);
        }

        DB::table('scheduled_trips')
            ->whereNull('promised_departure_at')
            ->whereNotNull('promised_arrival_at')
            ->update(['estimated_arrival_at' => DB::raw('promised_arrival_at')]);
    }

    public function down(): void
    {
        DB::table('commute_offers')->where('direction', 'going')->update(['direction' => 'to_work']);
        DB::table('commute_offers')->where('direction', 'return')->update(['direction' => 'to_home']);

        Schema::table('commute_demands', function (Blueprint $table): void {
            $table->dropIndex(['paired_demand_id']);
            $table->dropColumn(['direction', 'leave_at', 'paired_demand_id']);
        });

        Schema::table('scheduled_trips', function (Blueprint $table): void {
            $table->dropColumn(['promised_departure_at', 'estimated_arrival_at']);
        });

        Schema::table('commute_weekday_schedules', function (Blueprint $table): void {
            $table->dropColumn(['promised_departure_time', 'estimated_arrival_time']);
        });

        Schema::table('commute_offers', function (Blueprint $table): void {
            $table->dropForeign(['return_of_commute_id']);
            $table->dropColumn('return_of_commute_id');
        });
    }
};
