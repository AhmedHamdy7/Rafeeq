<?php

use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $schedules = DB::table('commute_schedules')
            ->whereNotIn('commute_offer_id', DB::table('commute_weekday_schedules')->select('commute_offer_id'))
            ->select('commute_offer_id', 'days_mask', 'departure_time')
            ->get();

        $now = now();
        $rows = [];

        foreach ($schedules as $schedule) {
            foreach (DaysMask::fromBits((int) $schedule->days_mask)->bits() as $bit) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'commute_offer_id' => $schedule->commute_offer_id,
                    'weekday_bit' => $bit,
                    'departure_time' => $schedule->departure_time,
                    'promised_arrival_time' => null,
                    'seats_total' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('commute_weekday_schedules')->insert($chunk);
        }
    }

    public function down(): void {}
};
