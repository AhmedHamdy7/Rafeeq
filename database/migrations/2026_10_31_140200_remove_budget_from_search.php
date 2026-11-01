<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The passenger's monthly budget leaves search, demands and the score (Feature 03 S-2, decision
 * C11).
 *
 * The seat price is computed by RAFEEQ now (PAY-42) and is the same everywhere for the same trip,
 * so there is nothing for a budget to choose between: it can only hide commutes, and BOOK-02 says
 * there is no price filter. Dropped rather than left unused, because a column nothing reads still
 * looks like a feature to whoever reads the schema next.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('commute_demands', 'budget_monthly_piastres')) {
            Schema::table('commute_demands', function (Blueprint $table) {
                $table->dropColumn('budget_monthly_piastres');
            });
        }

        if (Schema::hasColumn('match_scores', 'price_score')) {
            Schema::table('match_scores', function (Blueprint $table) {
                $table->dropColumn('price_score');
            });
        }
    }

    public function down(): void
    {
        Schema::table('commute_demands', function (Blueprint $table) {
            $table->unsignedInteger('budget_monthly_piastres')->nullable();
        });

        Schema::table('match_scores', function (Blueprint $table) {
            $table->unsignedTinyInteger('price_score')->default(0);
        });
    }
};
