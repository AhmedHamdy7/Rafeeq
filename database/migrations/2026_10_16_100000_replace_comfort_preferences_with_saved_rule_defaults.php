<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴 Undoing a table I added earlier today, because it duplicated something that already existed.
 *
 * `user_comfort_preferences` stored smoking/music/conversation/AC as a parallel vocabulary. But
 * `CommuteRuleKey` already holds **exactly** the six chips the design shows — `nonsmoking`,
 * `quiet`, `ac`, `nofood`, `luggage`, `front` — and they were already wired end to end:
 *
 *   - the DRIVER's side lives on her commute as `commute_rules` ("conditions offered/allowed")
 *   - the PASSENGER's side arrives per search as `requiredRules` ("desired environment")
 *   - together they feed `comfort_score` as a SOFT score, never a hard filter — which is precisely
 *     what Feature 02 v3 asks for ("avoid claiming these are strict filters")
 *
 * So the table was a second place comfort could live, with its own enum, read by nothing. That is
 * the `auth.pin.length` mistake again: a stored value no code consults, which an operator or a
 * client can change and see confirmed while nothing happens.
 *
 * What was genuinely missing is narrower and more useful: the passenger re-ticks those chips on
 * **every search**, because nothing remembers them. So the defaults move onto `user_preferences`,
 * which is already the 1:1 home for cross-device UI state, as two lists of the SAME `CommuteRuleKey`
 * values — one per mode, which is what v3 means by mode-specific content.
 *
 * No data is lost that anybody could have seen: the dropped table was created hours ago, nothing
 * read it, and no client shipped against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            /*
             * JSON lists of `CommuteRuleKey` values, not a column per chip.
             *
             * A column each would mean a migration every time the product adds a chip, and the set
             * is the design's to change. A list also makes "she has expressed no preference"
             * (`null`) distinct from "she wants none of them" (`[]`) — which matters, because the
             * first must never narrow a search and the second is a real answer.
             */
            $table->json('passenger_comfort_rules')->nullable()->after('last_active_mode');
            $table->json('driver_comfort_rules')->nullable()->after('passenger_comfort_rules');
        });

        Schema::dropIfExists('user_comfort_preferences');
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn(['passenger_comfort_rules', 'driver_comfort_rules']);
        });

        /*
         * Deliberately NOT recreating `user_comfort_preferences`. Rolling back should return the
         * schema to a state somebody wanted, and nobody wanted that table — it existed for a few
         * hours and was read by nothing. Re-creating it on a rollback would reintroduce the
         * duplicate this migration exists to remove.
         */
    }
};
