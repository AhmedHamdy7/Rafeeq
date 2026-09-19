<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔒 INSERT-only — the single source of truth for a driver's cash-fee
     * debt (Bible §4/§Part 2, group ⑨). `driver_balances.outstanding_fee`
     * is a projection of this table, reconciled daily; if they ever
     * disagree, this table wins.
     */
    public function up(): void
    {
        Schema::create('driver_fee_ledger', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_profile_id')->constrained('driver_profiles', 'user_id');
            $table->foreignUlid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('type', 20); // fee_due|fee_settled|adjustment|write_off
            $table->integer('amount_piastres'); // signed: positive increases debt, negative pays it down
            $table->integer('balance_after_piastres'); // running total, for audit
            $table->foreignUlid('settled_from_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['driver_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_fee_ledger');
    }
};
