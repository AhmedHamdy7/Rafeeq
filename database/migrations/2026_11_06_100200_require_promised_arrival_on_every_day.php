<?php

use App\Domains\Commute\Support\DepartureTimeCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const int DEFAULT_ROUTE_SECONDS = 3600;

    private const int MARGIN_MINUTES = 10;

    public function up(): void
    {
        $rows = DB::table('commute_weekday_schedules')
            ->join('commute_offers', 'commute_offers.id', '=', 'commute_weekday_schedules.commute_offer_id')
            ->whereNull('commute_weekday_schedules.promised_arrival_time')
            ->select('commute_weekday_schedules.id', 'commute_weekday_schedules.departure_time', 'commute_offers.route_duration_seconds')
            ->get();

        foreach ($rows as $row) {
            [$hours, $minutes] = array_map('intval', explode(':', (string) $row->departure_time));

            $total = $hours * 60 + $minutes + (int) ceil(((int) ($row->route_duration_seconds ?: self::DEFAULT_ROUTE_SECONDS)) / 60) + self::MARGIN_MINUTES;
            $total = min(23 * 60 + 55, (int) ceil($total / 5) * 5);

            DB::table('commute_weekday_schedules')->where('id', $row->id)->update([
                'promised_arrival_time' => sprintf('%02d:%02d:00', intdiv($total, 60), $total % 60),
            ]);
        }

        DB::table('commute_weekday_schedules')->update(['departure_is_computed' => true]);

        $days = DB::table('scheduled_trips')
            ->join('commute_schedules', 'commute_schedules.id', '=', 'scheduled_trips.commute_schedule_id')
            ->whereNull('scheduled_trips.promised_arrival_at')
            ->where('scheduled_trips.status', 'scheduled')
            ->where('scheduled_trips.departure_at', '>', now())
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
