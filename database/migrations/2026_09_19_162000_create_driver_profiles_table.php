<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            // 1:1 with users — the FK is the primary key, no separate id.
            $table->foreignUlid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('draft'); // draft|pending_review|approved|rejected|suspended|expired_documents
            $table->string('national_id_hash', 64)->nullable(); // search without decrypting
            $table->text('national_id_encrypted')->nullable();
            $table->string('licence_number_hash', 64)->nullable();
            $table->text('licence_number_encrypted')->nullable();
            $table->date('licence_expiry')->nullable(); // checked daily + before every trip
            $table->timestamp('verified_at')->nullable();
            $table->foreignUlid('reviewer_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('rejection_reason', 255)->nullable();
            $table->unsignedInteger('completed_trips_count')->default(0); // denormalized
            $table->decimal('cancellation_rate', 5, 2)->nullable();
            $table->decimal('on_time_rate', 5, 2)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('national_id_hash');
            $table->index('licence_number_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
    }
};
