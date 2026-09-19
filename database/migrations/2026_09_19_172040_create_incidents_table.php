<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id')->nullable()->constrained('bookings')->nullOnDelete(); // a report may exist without a booking
            $table->foreignUlid('trip_session_id')->nullable()->constrained('trip_sessions')->nullOnDelete();
            $table->foreignUlid('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('reported_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 30); // harassment|unsafe_driving|identity_mismatch|payment|no_show|lost_item|other
            $table->string('severity', 20); // low|medium|high|critical
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open'); // open|under_review|escalated|resolved|closed
            $table->foreignUlid('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->dateTime('sla_due_at')->nullable();
            $table->string('resolution', 255)->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['assigned_admin_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
