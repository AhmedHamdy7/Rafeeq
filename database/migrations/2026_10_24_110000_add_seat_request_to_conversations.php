<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat on a seat request, before there is any booking (Feature 03 v5).
 *
 * > *"No Messages tab; chat scoped to request / booking / waiting-list."*
 *
 * 🔴 **The thread has to exist before the seat does.** Until now a conversation needed a confirmed
 * booking, which put the one exchange that decides a booking out of reach: a driver weighing a
 * request cannot ask "can you be at the second gate instead?" and a passenger cannot ask anything
 * at all about the car she is about to get into. v5 scopes chat to the request for exactly that
 * reason, and `conversations` already had two context columns — this is the third.
 *
 * ⚠️ **And it is not consent.** v5 §2 says so in terms: "chat is not consent". Agreeing a corner in
 * a message changes nothing — the meeting point moves only through a counteroffer, which is
 * structured and recorded. This column adds a place to talk, never a second way to agree.
 *
 * 🔒 The unique indexes are new on both columns. One conversation per context was already true,
 * kept so by `firstOrCreate` under a row lock, but a lock is a procedure and an index is a fact.
 * MySQL permits many NULLs in a unique index, which is what lets one table hold three kinds of
 * context with one row per thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            /*
             * `restrict` on update and `set null` on delete, matching `booking_id` beside it: a
             * thread outlives the request it was about, because what was said in it is evidence
             * long after the request itself stopped mattering.
             */
            $table->foreignUlid('seat_request_id')
                ->nullable()
                ->after('booking_id')
                ->constrained('seat_requests')
                ->nullOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unique('seat_request_id', 'conversations_one_per_seat_request');
            $table->unique('booking_id', 'conversations_one_per_booking');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique('conversations_one_per_booking');
            $table->dropUnique('conversations_one_per_seat_request');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seat_request_id');
        });
    }
};
