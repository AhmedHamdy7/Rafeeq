<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home · Customise (H01): which Home cards each staff member chose to see. Null is "all of
 * them", so nobody's Home changes until they choose. Guarded, so a re-run after a
 * half-finished deploy does not fail on a column that is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('admin_users', 'home_cards')) {
            return;
        }

        Schema::table('admin_users', function (Blueprint $table) {
            $table->json('home_cards')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('admin_users', 'home_cards')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->dropColumn('home_cards');
            });
        }
    }
};
