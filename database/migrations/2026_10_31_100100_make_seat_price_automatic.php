<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The seat price is computed by RAFEEQ, not set by the driver (P-1 → P-5; PAY-39 → PAY-44, PAY-48,
 * D-0016, D-0025, D-0026).
 *
 * **Trip days** (`scheduled_trips`) carry the price and everything it was computed from, frozen when
 * the day is generated (PAY-44): a settings change tomorrow must never move a day that already
 * exists. `price_snapshot_piastres` becomes `seat_price_piastres` — the name every workstream agreed
 * on — and is joined by the driver's share and RAFEEQ's commission per seat, with
 * `seat = share + commission` held by a CHECK exactly as `bookings` holds its own three figures.
 *
 * 🔴 How the "never rounded" share is stored: in whole **piastres**, rounded half-up from the exact
 * value. PAY-43's "the driver's share is never rounded" is about the 5-EGP step, which would move
 * real money between the driver and RAFEEQ; a piastre is the unit every money column here uses
 * (binding standard #2), and the sub-piastre remainder falls to RAFEEQ through
 * `commission = seat − share`, which is exactly where PAY-43 puts every rounding difference. The
 * formula itself runs in milli-piastres so that remainder is never larger than half a piastre.
 *
 * The `pricing_*` columns are null on days generated before this change, which were priced by the
 * driver: there is no formula to describe them, and inventing one would be a lie in an audit
 * column. Their share is backfilled as the whole price and the commission as 0, which is what the
 * day itself ever promised (the old 3% fee lived on the booking, not on the day).
 *
 * **Commutes** keep a cache of the current price (`seat_price_piastres`, `driver_share_piastres`),
 * written only by the server when the commute is published or its days are generated. Null on a
 * draft, which has no route length to price yet.
 *
 * **Weekdays** lose their own price: the route and the car are the same every day, so a per-day
 * price could only ever be a driver-set one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->renameColumn('price_snapshot_piastres', 'seat_price_piastres');
        });

        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->unsignedInteger('driver_share_piastres')->default(0)->after('seat_price_piastres');
            $table->unsignedInteger('commission_piastres')->default(0)->after('driver_share_piastres');

            $table->unsignedInteger('pricing_route_distance_meters')->nullable()->after('commission_piastres');
            $table->string('pricing_vehicle_tier', 16)->nullable()->after('pricing_route_distance_meters');
            $table->unsignedInteger('pricing_km_rate_piastres')->nullable()->after('pricing_vehicle_tier');
            $table->unsignedTinyInteger('pricing_approved_seats')->nullable()->after('pricing_km_rate_piastres');
            $table->unsignedTinyInteger('pricing_seat_divisor')->nullable()->after('pricing_approved_seats');
            $table->unsignedTinyInteger('pricing_commission_rate_percent')->nullable()->after('pricing_seat_divisor');
            $table->unsignedInteger('pricing_min_commission_piastres')->nullable()->after('pricing_commission_rate_percent');
            $table->unsignedInteger('pricing_rounding_step_piastres')->nullable()->after('pricing_min_commission_piastres');
        });

        DB::table('scheduled_trips')->update(['driver_share_piastres' => DB::raw('seat_price_piastres')]);

        DB::statement('ALTER TABLE scheduled_trips ADD CONSTRAINT chk_scheduled_trips_seat_price '
            .'CHECK (seat_price_piastres = driver_share_piastres + commission_piastres)');

        Schema::table('commute_offers', function (Blueprint $table) {
            $table->renameColumn('price_per_seat_piastres', 'seat_price_piastres');
        });

        Schema::table('commute_offers', function (Blueprint $table) {
            $table->unsignedInteger('seat_price_piastres')->nullable()->change();
            $table->unsignedInteger('driver_share_piastres')->nullable()->after('seat_price_piastres');
        });

        // Drafts were priced by hand; they will be priced by the server when published.
        DB::table('commute_offers')->where('status', 'draft')->update(['seat_price_piastres' => null]);

        DB::table('commute_offers')->whereNotNull('seat_price_piastres')
            ->update(['driver_share_piastres' => DB::raw('seat_price_piastres')]);

        Schema::table('commute_weekday_schedules', function (Blueprint $table) {
            $table->dropColumn('price_per_seat_piastres');
        });
    }

    public function down(): void
    {
        Schema::table('commute_weekday_schedules', function (Blueprint $table) {
            $table->integer('price_per_seat_piastres')->nullable();
        });

        DB::table('commute_offers')->whereNull('seat_price_piastres')->update(['seat_price_piastres' => 0]);

        Schema::table('commute_offers', function (Blueprint $table) {
            $table->dropColumn('driver_share_piastres');
        });

        Schema::table('commute_offers', function (Blueprint $table) {
            $table->unsignedInteger('seat_price_piastres')->nullable(false)->change();
        });

        Schema::table('commute_offers', function (Blueprint $table) {
            $table->renameColumn('seat_price_piastres', 'price_per_seat_piastres');
        });

        DB::statement('ALTER TABLE scheduled_trips DROP CONSTRAINT chk_scheduled_trips_seat_price');

        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dropColumn([
                'driver_share_piastres', 'commission_piastres', 'pricing_route_distance_meters',
                'pricing_vehicle_tier', 'pricing_km_rate_piastres', 'pricing_approved_seats',
                'pricing_seat_divisor', 'pricing_commission_rate_percent',
                'pricing_min_commission_piastres', 'pricing_rounding_step_piastres',
            ]);
        });

        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->renameColumn('seat_price_piastres', 'price_snapshot_piastres');
        });
    }
};
