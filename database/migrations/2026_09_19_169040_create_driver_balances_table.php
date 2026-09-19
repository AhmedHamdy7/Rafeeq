<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_balances', function (Blueprint $table) {
            // 1:1 with driver_profiles — the FK is the primary key.
            $table->foreignUlid('driver_profile_id')->primary()->constrained('driver_profiles', 'user_id')->cascadeOnDelete();
            $table->unsignedInteger('outstanding_fee_piastres')->default(0); // PROJECTION of driver_fee_ledger
            $table->unsignedBigInteger('lifetime_earnings_piastres')->default(0);
            $table->dateTime('last_settled_at')->nullable();
            $table->boolean('is_blocked_from_publishing')->default(false);
            $table->string('block_reason', 255)->nullable();
            $table->dateTime('reconciled_at')->nullable(); // last drift check against the ledger
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_balances');
    }
};
