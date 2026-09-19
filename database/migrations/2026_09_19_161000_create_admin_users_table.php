<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->string('password_hash', 255);
            $table->text('mfa_secret'); // mandatory — encrypted at rest
            $table->timestamp('mfa_confirmed_at')->nullable();
            $table->string('status', 20)->default('active'); // active|suspended
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip_hash', 64)->nullable();
            // Required by the session-driver `admin` guard: SessionGuard::logout()
            // reads getRememberToken() unconditionally, which throws under
            // Model::shouldBeStrict() if the column is absent.
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
