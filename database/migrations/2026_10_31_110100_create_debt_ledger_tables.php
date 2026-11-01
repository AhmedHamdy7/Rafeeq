<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The debt-only ledger for drivers AND passengers (PAY-08, decision D-0005), its projection, and
 * the coupons staff issue from it (PAY-34).
 *
 * 🔴 Why a NEW user-level table rather than widening `driver_fee_ledger`:
 *
 * - That table is keyed by `driver_profile_id`. A passenger has no driver profile, so a passenger's
 *   fee could not be written to it at all — and the whole point of PAY-16/PAY-17 is that passengers
 *   owe money too.
 * - Its row shape was one-sided (`fee_due` up, `fee_settled` down) and only `fee_due` was ever
 *   written. The new one carries what the weekly cycle needs to be computed rather than tracked:
 *   which side of the person the line is on, and which Saturday→Friday week it belongs to.
 * - Renaming a column under a foreign key and rewriting every row in place is the same amount of
 *   work as copying, with none of the safety: here the old rows are copied in the next migration
 *   (`2026_10_31_110200_move_driver_fee_ledger_into_debt_entries`) and only then is the old table
 *   dropped.
 *
 * `debt_entries` is append-only (standard #9/#20). A fee overturned on appeal is not edited; a
 * `credit` (or a negative `adjustment`) is added that points at it through `reverses_entry_id`.
 *
 * `debt_accounts` is the projection: one row per person per side, written in the same transaction
 * as each ledger line under a row lock, and compared to the ledger every night (pitfall #39).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users');
            $table->string('side', 10); // driver|passenger
            $table->string('type', 20); // commission|fee|late_fee|credit|payment|coupon|fee_line|adjustment|write_off
            // Signed: positive adds to what she owes, negative reduces it.
            $table->bigInteger('amount_piastres');
            // The side's running balance after this line. Negative = a credit she will be discounted.
            $table->bigInteger('balance_after_piastres');
            // Machine reason: `seat_commission`, `passenger_no_show`, `late_payment`, `instapay`…
            $table->string('reason_code', 50);
            // The Saturday (Cairo) that starts the settlement week this line belongs to (PAY-11).
            $table->date('week_of');
            $table->foreignUlid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignUlid('debt_payment_id')->nullable()->constrained('debt_payments')->nullOnDelete();
            // A credit or correction names the line it answers (an appeal upheld after payment).
            $table->foreignUlid('reverses_entry_id')->nullable()->constrained('debt_entries')->nullOnDelete();
            $table->foreignUlid('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            // "Once" made true by the database: one commission per booking, one late fee per week.
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'side', 'week_of']);
            $table->index(['side', 'type', 'created_at']);
            $table->index('booking_id');
            $table->index('debt_payment_id');
            $table->index('reverses_entry_id');
            $table->index('created_by_admin_id');
        });

        Schema::create('debt_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users');
            $table->string('side', 10);
            $table->bigInteger('balance_piastres')->default(0); // PROJECTION of debt_entries
            /*
             * Driver side only: when the week-2 rule suspended her from driving (PAY-45). Not an
             * account suspension — she keeps Passenger mode — so it does not live on `users` or in
             * `account_suspensions`, whose every row means "cannot use RAFEEQ at all".
             */
            $table->dateTime('suspended_at')->nullable();
            /*
             * The Cairo day the last debt reminder went out (Thursday "due tomorrow", the daily
             * "overdue", the warning before suspension). At most one a day, whatever the sweep's
             * cadence or a manual re-run: a reminder repeated is a reminder ignored.
             */
            $table->date('last_reminded_on')->nullable();
            $table->dateTime('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'side']);
            $table->index(['side', 'balance_piastres']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users'); // who benefits
            $table->string('side', 10);
            $table->unsignedBigInteger('value_piastres');
            // The fee collected from the OTHER side that justified it. Unique: one coupon per fee.
            $table->foreignUlid('source_entry_id')->unique()->constrained('debt_entries');
            // The coupon's own line on the beneficiary's ledger.
            $table->foreignUlid('debt_entry_id')->constrained('debt_entries');
            $table->foreignUlid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignUlid('issued_by_admin_id')->constrained('admin_users');
            $table->string('note', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('debt_entry_id');
            $table->index('booking_id');
            $table->index('issued_by_admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('debt_accounts');
        Schema::dropIfExists('debt_entries');
    }
};
