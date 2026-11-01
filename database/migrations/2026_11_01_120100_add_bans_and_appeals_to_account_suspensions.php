<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A conduct suspension stays in the app; only a permanent ban does not (AUTH-19, D-0018,
 * follow-up F1-3).
 *
 * `is_permanent` is the one fact that splits the two: a suspended member lands on Home with a
 * banner, Appeal and her trips read-only, while a banned one gets the full Account restricted
 * screen. It is a column on the hold rather than a new account status because everything else
 * about the two is the same — the case number, the review time, the lift.
 *
 * `suspension_appeals` holds her answer: one appeal within 48 hours of the hold, and a second
 * only with new evidence, within 48 hours of the first being turned down. Staff decide each one;
 * overturning lifts the hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_suspensions', function (Blueprint $table) {
            $table->boolean('is_permanent')->default(false)->after('reason_code');
        });

        Schema::create('suspension_appeals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_suspension_id')->constrained('account_suspensions');
            $table->foreignUlid('user_id')->constrained('users');
            $table->unsignedTinyInteger('attempt'); // 1, or 2 with new evidence
            $table->string('message', 1000);
            $table->string('evidence', 2000)->nullable();
            $table->string('resolution', 20)->nullable(); // upheld|overturned
            $table->foreignUlid('resolved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['account_suspension_id', 'attempt']);
            $table->index('user_id');
            $table->index(['resolution', 'created_at']);
            $table->index('resolved_by_admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspension_appeals');

        Schema::table('account_suspensions', function (Blueprint $table) {
            $table->dropColumn('is_permanent');
        });
    }
};
