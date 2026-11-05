<?php

use App\Domains\Commute\Support\DepartureTimeCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $days = DB::table('scheduled_trips')
            ->join('commute_schedules', 'commute_schedules.id', '=', 'scheduled_trips.commute_schedule_id')
            ->whereNull('scheduled_trips.promised_arrival_at')
            ->where('scheduled_trips.status', 'scheduled')
            ->where('scheduled_trips.departure_at', '>', now())
            ->where('scheduled_trips.seats_taken', 0)
            ->select('scheduled_trips.id', 'scheduled_trips.commute_offer_id', 'scheduled_trips.trip_date', 'scheduled_trips.departure_at', 'commute_schedules.timezone')
            ->orderBy('scheduled_trips.id')
            ->get();

        foreach ($days as $day) {
            $date = substr((string) $day->trip_date, 0, 10);
            $bit = 1 << (((int) date('w', (int) strtotime($date)) + 1) % 7);

            $arrival = DB::table('commute_weekday_schedules')
                ->where('commute_offer_id', $day->commute_offer_id)
                ->where('weekday_bit', $bit)
                ->value('promised_arrival_time');

            if ($arrival === null) {
                continue;
            }

            $at = DepartureTimeCalculator::toUtc($date, (string) $arrival, (string) ($day->timezone ?: 'Africa/Cairo'));

            if ($at->greaterThan($day->departure_at)) {
                DB::table('scheduled_trips')->where('id', $day->id)->update(['promised_arrival_at' => $at]);
            }
        }
    }

    public function down(): void {}
};
