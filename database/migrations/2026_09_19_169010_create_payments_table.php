<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id')->constrained('bookings'); // RESTRICT — 7yr tax retention (ERD §18)
            $table->foreignUlid('user_id')->constrained('users'); // the payer
            $table->foreignUlid('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete(); // null for cash
            $table->string('payment_type', 10); // cash | online
            $table->string('provider', 20)->nullable();
            $table->string('provider_ref', 120)->nullable();
            $table->unsignedInteger('amount_piastres');
            $table->unsignedInteger('platform_fee_piastres');
            $table->unsignedInteger('driver_amount_piastres');
            $table->string('type', 20); // charge|refund|partial_refund
            $table->string('status', 25)->default('pending'); // pending|authorized|captured|failed|refunded|settled_offline
            $table->string('idempotency_key', 64); // 🔴 the double-charge guard
            $table->dateTime('authorized_at')->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->string('failure_code', 50)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->dateTime('confirmed_by_driver_at')->nullable(); // cash only
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['booking_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('provider_ref');
        });

        // ERD §20 constraint #16 — no penny ever goes missing between the
        // platform's cut and the driver's share.
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT chk_payments_amount_split '.
            'CHECK (amount_piastres = platform_fee_piastres + driver_amount_piastres)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
