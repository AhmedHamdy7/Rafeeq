<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fee a warning carries, now that the ladders are decided (PAY-20 → PAY-22; Feature 03 backend
 * file F-1 → F-3).
 *
 * Frozen on the row at the moment it is recorded — the step, the percentage and the amount — so a
 * settings change never rewrites what somebody was charged.
 *
 * `fee_status`:
 * - `none` — a warning, no money;
 * - `pending` — a late cancellation whose fee waits for departure: free if the seat is refilled
 *   from the waiting list meanwhile (PAY-21, R-8);
 * - `charged` — on her ledger (`debt_entry_id`);
 * - `waived` — never charged: the seat was refilled, or the appeal won before it was;
 * - `reversed` — charged, then credited back after an upheld appeal (PAY-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_warnings', function (Blueprint $table) {
            $table->unsignedSmallInteger('fee_basis_points')->default(0)->after('fee_eligible');
            $table->unsignedBigInteger('fee_piastres')->default(0)->after('fee_basis_points');
            $table->string('fee_status', 20)->default('none')->after('fee_piastres');
            $table->foreignUlid('debt_entry_id')->nullable()->after('fee_status')->constrained('debt_entries')->nullOnDelete();

            $table->index(['kind', 'fee_status'], 'member_warnings_pending_fees');
        });
    }

    public function down(): void
    {
        Schema::table('member_warnings', function (Blueprint $table) {
            $table->dropIndex('member_warnings_pending_fees');
            $table->dropConstrainedForeignId('debt_entry_id');
            $table->dropColumn(['fee_basis_points', 'fee_piastres', 'fee_status']);
        });
    }
};
