<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A driver's appeal against one of his fees (SAFE-11, PAY-10; PAY-23 → PAY-29's "evidence appeal
 * within 48h").
 *
 * A passenger's fee rides on a warning and is appealed there (`member_warnings.disputed_at`); a
 * driver's fees have no warning behind them, so the appeal points at the ledger line itself. One
 * per fee. Upheld → nothing moves; overturned → a credit for the whole fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_appeals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('debt_entry_id')->unique()->constrained('debt_entries');
            $table->foreignUlid('user_id')->constrained('users');
            $table->string('reason', 1000);
            $table->string('resolution', 20)->nullable(); // upheld|overturned
            $table->foreignUlid('resolved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution_note', 255)->nullable();
            $table->timestamps();

            $table->index(['resolution', 'created_at']);
            $table->index('user_id');
            $table->index('resolved_by_admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_appeals');
    }
};
