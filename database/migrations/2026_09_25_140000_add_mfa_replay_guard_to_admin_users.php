<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔒 Records the TOTP time-step an admin's last accepted code came from, so the same
 * code cannot be used twice.
 *
 * Without it, a code is valid for its whole 30-second step plus the tolerance window
 * either side — several accepted uses of one six-digit number. TOTP's entire promise is
 * that a code is spent once, and a captured code (shoulder-surfed, read off a phone,
 * replayed from a proxy) is exactly the case the second factor exists to stop. Every
 * TOTP library leaves this to the application because only the application has
 * somewhere to remember it.
 *
 * An integer step counter rather than a timestamp: it is what `verifyKeyNewer()` both
 * takes and returns, and comparing steps is what makes "newer than the last one" exact
 * rather than a clock comparison with its own rounding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->unsignedInteger('mfa_last_used_timestamp')
                ->nullable()
                ->after('mfa_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn('mfa_last_used_timestamp');
        });
    }
};
