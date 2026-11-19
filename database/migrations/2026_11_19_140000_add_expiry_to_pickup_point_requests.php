<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickup_point_requests', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('effective_from');
            $table->timestamp('expired_at')->nullable()->after('expires_at');
            $table->index(['status', 'expires_at'], 'pickup_point_requests_status_expires');
        });
    }

    public function down(): void
    {
        Schema::table('pickup_point_requests', function (Blueprint $table) {
            $table->dropIndex('pickup_point_requests_status_expires');
            $table->dropColumn(['expires_at', 'expired_at']);
        });
    }
};
