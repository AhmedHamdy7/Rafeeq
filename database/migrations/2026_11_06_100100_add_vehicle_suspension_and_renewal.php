<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('suspension_reason', 30)->nullable()->after('verification_status');
            $table->dateTime('registration_renewal_submitted_at')->nullable()->after('suspension_reason');
        });

        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->dateTime('expiry_warned_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->dropColumn('expiry_warned_for');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['suspension_reason', 'registration_renewal_submitted_at']);
        });
    }
};
