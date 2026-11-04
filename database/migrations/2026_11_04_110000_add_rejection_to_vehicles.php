<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('rejected_item', 32)->nullable()->after('verification_status');
            $table->string('rejection_reason', 255)->nullable()->after('rejected_item');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['rejected_item', 'rejection_reason']);
        });
    }
};
