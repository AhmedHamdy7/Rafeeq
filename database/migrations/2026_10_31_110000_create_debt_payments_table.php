<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An InstaPay payment a member says she sent, waiting for staff to see it arrive (PAY-09, ADM-03).
 *
 * Its own table rather than a row in `payments`: that table is the fare for one seat on one booking,
 * collected in the car. This is a member paying down what she owes RAFEEQ — commission, fees, late
 * fees — with no booking behind it, and it can be refused. Nothing comes off a debt until the row is
 * `confirmed`; the ledger line that does it points back here.
 *
 * The receipt is optional and lives on the private documents disk, through the same intake as
 * identity documents and report evidence (scan, re-encode, then store).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users');
            // Which of her two debts it pays (DebtSide): a commission and a fee are settled apart.
            $table->string('side', 10);
            $table->unsignedBigInteger('amount_piastres');
            // The reference InstaPay shows the sender. What staff search their bank statement for.
            $table->string('instapay_reference', 64);
            $table->string('receipt_path', 255)->nullable();
            $table->string('receipt_hash', 64)->nullable(); // chain of custody, as for evidence
            $table->string('status', 30); // pending_confirmation|confirmed|rejected
            $table->foreignUlid('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            // Shown to the member when a payment is refused ("no transfer with this reference").
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('decided_by_admin_id');
            $table->index('instapay_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
    }
};
