<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables · saved views: each staff member's named filter sets, per page. Guarded, so a
 * re-run after a half-finished deploy does not fail on a column that is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('admin_users', 'table_views')) {
            return;
        }

        Schema::table('admin_users', function (Blueprint $table) {
            $table->json('table_views')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('admin_users', 'table_views')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->dropColumn('table_views');
            });
        }
    }
};
