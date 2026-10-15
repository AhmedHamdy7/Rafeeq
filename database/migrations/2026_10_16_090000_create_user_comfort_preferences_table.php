<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person wants the inside of the car to be like (Feature 02 v3: "Comfort & atmosphere").
 *
 * One row per person PER MODE, because the same four questions mean opposite things depending on
 * which side you are on: v3 — "Passenger: desired environment; Driver: conditions offered/allowed."
 * A passenger saying `no` to smoking is asking for something; a driver saying `no` is setting a
 * rule for her own car. Same column, two readings, so they cannot share a row.
 *
 * 🔴 **These are NOT filters, and the migration says so because the schema is where somebody will
 * come looking for a reason to join on them.** v3 is explicit: "Avoid claiming these are strict
 * filters until matching rules are defined." So nothing in `HardFilters` or the match score reads
 * this table, and nothing should until that decision is made — a passenger who asked for a quiet
 * car and got a chatty driver has a mild disappointment; a passenger silently excluded from every
 * commute because she ticked a box has an empty search result she cannot explain.
 *
 * Deliberately no `smoking_allowed`-style booleans: every field is a three-way preference, because
 * "no preference" is a real and common answer and a boolean would force it to look like "no".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_comfort_preferences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            // passenger | driver — the same two values as a capability, because the mode IS which
            // capability you are using.
            $table->string('mode', 20);

            // yes | no | either, each defaulting to `either`: a person who has never opened this
            // screen has expressed no preference, which is different from having said no.
            $table->string('smoking', 20)->default('either');
            $table->string('music', 20)->default('either');
            $table->string('conversation', 20)->default('either');
            $table->string('air_conditioning', 20)->default('either');

            $table->timestamps();

            // One row per person per mode. Enforced here rather than only in the Action, because
            // the endpoint is a PATCH that a flaky connection will send twice.
            $table->unique(['user_id', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_comfort_preferences');
    }
};
