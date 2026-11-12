<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time links that bring a staff member through the first sign-in (S05 set a password,
 * S06 set up the authenticator) — after an invitation, or after an Admin resets their
 * access. Only a SHA-256 of the token is stored: the link is shown once, to the Admin who
 * made it, and a copy of this table is worth nothing without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignUlid('invited_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['admin_user_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invitations');
    }
};
