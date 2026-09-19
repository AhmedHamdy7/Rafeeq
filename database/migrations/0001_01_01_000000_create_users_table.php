<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The default Laravel `users` table is replaced entirely by the
        // Rafeeq Identity migration (ULID PK, phone_e164, gender, trust
        // level, etc. — Engineering Bible §4, ERD group ①). Laravel's own
        // password-reset and web-session tables are still needed as-is:
        // password_reset_tokens for the admin dashboard's email/password
        // login (Phase 13), and sessions for Livewire's HTTP session store.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // ulid, not foreignId: the authenticated web guard is admin_users
            // (ULID PK) — Livewire admin dashboard only, per decision D4.
            $table->ulid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
