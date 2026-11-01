<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appealing the decision on a report (HISTORY-10; D-0021, SAFE-11: "one appeal plus one second
 * appeal only with new evidence, then final", 48 hours from the decision).
 *
 * One row per appeal, at most two per report, never updated: the report itself carries where the
 * case stands (`incidents.status = appealed` while staff look again). `evidence_count` is how many
 * files were attached when the appeal was made — the fact a second appeal is measured against, so
 * "new evidence" is something the server can check rather than something the reporter asserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_appeals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('round'); // 1|2
            $table->string('reason', 1000);
            $table->unsignedSmallInteger('evidence_count');
            $table->timestamps();

            $table->unique(['incident_id', 'round']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_appeals');
    }
};
