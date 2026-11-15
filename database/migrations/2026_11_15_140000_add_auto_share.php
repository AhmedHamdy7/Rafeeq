<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('auto_share_trips')->default(false);
        });

        Schema::table('live_shares', function (Blueprint $table) {
            $table->string('source', 16)->default('manual');
        });

        Schema::create('auto_share_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('trip_session_id')->constrained('trip_sessions')->cascadeOnDelete();
            $table->foreignUlid('live_share_id')->nullable()->constrained('live_shares')->nullOnDelete();
            $table->foreignUlid('emergency_contact_id')->nullable()->constrained('emergency_contacts')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('to_e164', 20);
            $table->string('status', 16);
            $table->string('failure_reason')->nullable();
            $table->text('preview_url')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'trip_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_share_messages');

        Schema::table('live_shares', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auto_share_trips');
        });
    }
};
