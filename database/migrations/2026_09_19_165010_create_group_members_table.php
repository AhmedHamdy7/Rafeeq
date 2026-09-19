<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commute_group_id')->constrained('commute_groups')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20); // driver|member|trial
            $table->string('status', 20)->default('active'); // active|notice_given|left|removed
            $table->unsignedTinyInteger('committed_days_mask')->nullable();
            $table->dateTime('joined_at')->nullable();
            $table->dateTime('left_at')->nullable();
            $table->dateTime('notice_given_at')->nullable();
            $table->string('removal_reason', 255)->nullable();
            $table->timestamps();

            // ERD §20 constraint #10 — one membership per person per group.
            $table->unique(['commute_group_id', 'user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_members');
    }
};
