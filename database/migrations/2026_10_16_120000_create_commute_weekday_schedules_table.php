<?php

use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Each weekday of a commute gets its own departure, promised arrival, price and capacity
 * (Feature 03 v3: "Driver route fixed across recurring weekdays; **each weekday has independent
 * departure, promised arrival, price within route range, capacity and bookings**").
 *
 * 🔴 This is the riskiest migration in the plan, and the shape below is chosen to make it survivable
 * rather than elegant.
 *
 * Forty-two files read the schedule, and `commute_schedules.days_mask` in particular is on the
 * search hot path — `HardFilters` compares it against the passenger's requested days inside the
 * query. A faithful transcription of v3's model would move that fact into a child table and turn
 * every search into a join or a subquery, on the one code path where this product is already
 * slowest.
 *
 * So the split is by LIFETIME, not by tidiness:
 *
 *   - `commute_schedules` keeps what belongs to the commute as a whole — the date window,
 *     `generated_until`, the timezone — plus `days_mask` and `departure_time`, which become
 *     **DERIVED** columns maintained by `SaveCommuteScheduleAction`. Nothing writes them by hand.
 *   - this table holds what genuinely differs per weekday.
 *
 * The result is v3's model where it matters (generation, pricing and capacity all read per weekday)
 * with search untouched, and a derived mask that cannot drift because one Action owns it.
 *
 * ⚠️ `days_mask` and `departure_time` on the parent are therefore no longer the source of truth.
 * They are a cache of these rows. Writing to them directly will be silently overwritten the next
 * time a schedule is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commute_weekday_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commute_offer_id')->constrained('commute_offers')->cascadeOnDelete();

            /*
             * The `DaysMask` BIT — 1 Sat, 2 Sun, 4 Mon, 8 Tue, 16 Wed, 32 Thu, 64 Fri — rather than
             * a 0-6 index or a day name.
             *
             * It is the vocabulary the rest of the system already speaks: the parent's mask is the
             * sum of these, deriving it is an addition rather than a translation table, and there
             * is no second convention for somebody to get off by one.
             */
            $table->unsignedTinyInteger('weekday_bit');

            // Local wall clock, with the timezone on the parent. Never a UTC instant: a driver
            // saying 07:05 means the time on her clock, which is a different moment in April than
            // in December, and Egypt observes DST by law.
            $table->time('departure_time');

            /*
             * What she promises the passenger they will arrive by (v3). Nullable, because every
             * commute that exists today was published without one and inventing a promise on their
             * behalf would be putting words in a driver's mouth — and under the product decision
             * that a missed promise counts against her on-time rate, a fabricated one would cost
             * her a score she never agreed to.
             */
            $table->time('promised_arrival_time')->nullable();

            /*
             * Null means "whatever the commute says". v3 allows a per-weekday price and capacity;
             * most drivers will set neither, and a row full of copies of the parent's values is a
             * row that silently stops following it when the parent changes.
             */
            $table->integer('price_per_seat_piastres')->nullable();
            $table->unsignedTinyInteger('seats_total')->nullable();

            $table->timestamps();

            // One row per weekday per commute. Enforced here because the save action replaces the
            // whole set in a transaction and a retry must not double it.
            $table->unique(['commute_offer_id', 'weekday_bit']);
        });

        /*
         * Backfill every published and draft commute from the single row it has today, so nothing
         * stops generating trips the moment this lands.
         *
         * Done in PHP rather than one clever INSERT..SELECT because splitting a bitmask into rows
         * is exactly the kind of arithmetic that is unreadable in SQL and wrong in the corner
         * cases — and this runs once.
         */
        $schedules = DB::table('commute_schedules')
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
                    // No promise is invented for an existing commute — see the column note.
                    'promised_arrival_time' => null,
                    // Null so they keep following the commute's own price and capacity, which is
                    // what they did before this table existed.
                    'price_per_seat_piastres' => null,
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

    public function down(): void
    {
        Schema::dropIfExists('commute_weekday_schedules');
    }
};
