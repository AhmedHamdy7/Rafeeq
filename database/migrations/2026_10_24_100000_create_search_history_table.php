<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recent searches, account-synced (Feature 03 v5: `search_history(id, user_id, query, created_at)`).
 *
 * > *"On search-field focus show account-synced Recent Searches + `Clear All`; Clear All removes
 * > history, not demands."*
 *
 * 🔒 **This is a list of where somebody lives and where she works, with timestamps.** Of everything
 * this platform stores about a passenger it is among the most revealing, and unlike a booking it
 * exists only to save her typing. So three things follow, and all three are in the code rather than
 * in a policy document: it is readable by nobody but her, she can erase all of it in one call, and
 * it ages out on its own.
 *
 * 🔴 **Kept apart from `saved_searches` and `commute_demands`, which v5 is emphatic about:** "Clear
 * All removes history, not demands", and "search alone doesn't create a demand". Three tables
 * because they are three different promises — this one is a convenience she never asked for, a saved
 * search is one she named, and a demand is a standing instruction that makes the platform notify
 * her. Clearing the first must not touch the other two, and recording the first must never create
 * either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_history', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * Exactly what she sent, so the client can re-run the search by posting it back
             * unchanged. Storing a rendered summary instead would make the list unusable as
             * anything but text — and the one thing a recent search is for is being tapped.
             */
            $table->json('criteria');

            /*
             * 🔴 What makes "the same search" the same. Searching one route five times in a morning
             * is one entry in her list, not five — and without this the list would show nothing but
             * her last few keystrokes.
             *
             * Unique per user, so the dedupe is the database's answer rather than a read-then-write
             * that two taps can both pass.
             */
            $table->char('signature', 64);

            /*
             * When she last ran it, which is what the list is ordered by. Separate from
             * `created_at` on purpose: `created_at` is when the search was first seen and is what
             * retention measures, and bumping it to reorder the list would mean a search she keeps
             * re-running is never pruned.
             */
            $table->dateTime('searched_at');

            $table->timestamps();

            $table->unique(['user_id', 'signature']);

            // The only read: her list, newest first.
            $table->index(['user_id', 'searched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_history');
    }
};
