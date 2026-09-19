<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks Paymob's transfers to drivers for display/reconciliation — we
     * never hold a custodial balance (decision D15).
     */
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_profile_id')->constrained('driver_profiles', 'user_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('amount_piastres');
            $table->string('method', 20); // instapay|wallet|bank
            $table->string('status', 20)->default('pending'); // pending|cleared|on_hold|blocked|released
            $table->string('provider_transfer_ref', 120)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignUlid('released_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['driver_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
