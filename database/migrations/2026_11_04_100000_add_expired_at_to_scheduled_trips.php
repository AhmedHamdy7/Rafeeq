<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->timestamp('expired_at')->nullable()->after('delay_violation_at');
        });

        DB::statement("UPDATE scheduled_trips SET expired_at = updated_at, updated_at = updated_at WHERE status = 'expired'");
    }

    public function down(): void
    {
        Schema::table('scheduled_trips', function (Blueprint $table) {
            $table->dropColumn('expired_at');
        });
    }
};
