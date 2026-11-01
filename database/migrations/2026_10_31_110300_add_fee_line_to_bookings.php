<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The passenger's fee line on her next ride (PAY-16, decision D-0007; Feature 03 backend file L-11).
 *
 * 🔴 A separate line, never folded into the fare. The fare (`price_snapshot_piastres`) is frozen at
 * approval and stays what she agreed to; what she owes RAFEEQ rides along beside it on the next
 * booking that will actually happen, and the driver collects both in cash. Both people see it, and
 * the driver's screen shows which part is not his.
 *
 * Signed: positive is a fee she pays on top; negative is a credit (an appeal upheld after she paid,
 * PAY-10) that discounts this ride — the driver still keeps the full fare, the discount comes off
 * his commission debt.
 *
 * Kept in step with her passenger balance by the ledger itself, and moved onto the ledger as a pair
 * of `fee_line` entries (off her side, onto the driver's) the moment she is dropped off. Until then
 * it is a plan, not money: a cancelled ride carries nothing, and the line moves to the next one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->bigInteger('fee_line_piastres')->default(0)->after('cancellation_fee_piastres');
            // The reason code of what she owes (`passenger_no_show`, `late_cancel`, `credit`…).
            $table->string('fee_line_reason', 50)->nullable()->after('fee_line_piastres');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['fee_line_piastres', 'fee_line_reason']);
        });
    }
};
