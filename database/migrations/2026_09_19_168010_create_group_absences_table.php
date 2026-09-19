<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_absences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commute_group_id')->constrained('commute_groups')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('reason', 255)->nullable();
            $table->boolean('releases_seat')->default(false); // opens the seat to the waitlist

            $table->timestamps();
        });

        DB::statement('ALTER TABLE group_absences ADD CONSTRAINT chk_group_absences_range CHECK (to_date >= from_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('group_absences');
    }
};
