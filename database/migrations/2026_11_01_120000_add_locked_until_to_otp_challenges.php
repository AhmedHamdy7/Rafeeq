<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The OTP lock (AUTH-08, follow-up F1-5): three wrong codes lock the number for five minutes.
 *
 * The lock is stamped on the challenge that ran out of attempts rather than kept in a cache
 * key, so it survives a cache flush and an operator can see in the row why a person could not
 * sign in. The index serves the one question asked on every code request: "is there a lock on
 * this number for this purpose that has not run out yet?"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->dateTime('locked_until')->nullable()->after('max_attempts');
            $table->index(['phone_e164', 'purpose', 'locked_until']);
        });
    }

    public function down(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->dropIndex(['phone_e164', 'purpose', 'locked_until']);
            $table->dropColumn('locked_until');
        });
    }
};
