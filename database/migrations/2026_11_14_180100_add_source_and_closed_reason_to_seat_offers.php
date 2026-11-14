<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_offers', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('status');
            $table->string('closed_reason', 40)->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('seat_offers', function (Blueprint $table) {
            $table->dropColumn(['source', 'closed_reason']);
        });
    }
};
