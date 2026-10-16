<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a driver's `on_time_rate` came from — which rule computed it, and over how many runs.
 *
 * 🔴 This exists because the mobile guide already promises it, in my own words: "the metric
 * publishes `metricVersion` and the raw counts beside the percentage, so if the tolerance ever
 * changes you can tell that it did." It was written as a commitment and not implemented, so this
 * honours it rather than quietly editing the promise away.
 *
 * The reason behind it is real, and v3 states it: "expose metric version and counts to avoid
 * silently changing scores." A percentage whose DEFINITION moved is indistinguishable from a driver
 * whose driving got worse — and this figure is shown to strangers deciding whether to get into her
 * car. Two drivers at "88%" computed under different rules are not comparable, and nothing in the
 * data would say so.
 *
 * The sample size matters for the same reason: 88% over nine runs and 88% over four hundred are
 * different claims, and a client that cannot tell them apart will render both as a confident badge.
 *
 * ⚠️ Nullable, with NO backfill. A rate computed before this migration has no known version, and
 * stamping it with the current one would assert that it was computed under today's rule when it was
 * not — which is precisely the lie the column exists to prevent. Null means "computed under an
 * unrecorded rule", and the next completed trip recomputes it honestly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('on_time_metric_version')->nullable()->after('on_time_rate');

            // How many completed runs the percentage was computed over.
            $table->unsignedInteger('on_time_sample_size')->nullable()->after('on_time_metric_version');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['on_time_metric_version', 'on_time_sample_size']);
        });
    }
};
