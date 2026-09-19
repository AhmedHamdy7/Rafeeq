<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pitfall #41: `SUM(refunds.amount_piastres) <= payments.amount_piastres`
     * needs a cross-row aggregate, which a column-level CHECK can't express
     * — enforced in the refund-approval Action (Phase 8), not the schema.
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->unsignedInteger('amount_piastres');
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|processed|rejected
            $table->foreignUlid('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('provider_ref', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
