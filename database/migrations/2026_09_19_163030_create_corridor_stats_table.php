<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corridor_stats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('corridor_id')->constrained('corridors')->cascadeOnDelete();
            $table->date('stat_date');
            $table->unsignedInteger('drivers')->default(0);
            $table->unsignedInteger('seekers')->default(0);
            $table->decimal('fill_pct', 5, 2)->default(0);
            $table->timestamps();

            // Not in the ERD's own checklist, but implied by what this table
            // is: one daily snapshot per corridor, not several.
            $table->unique(['corridor_id', 'stat_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corridor_stats');
    }
};
