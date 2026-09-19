<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 🔒 Chain of custody for a real investigation — `file_hash` matters. */
    public function up(): void
    {
        Schema::create('incident_evidence', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('file_path', 255); // private, encrypted disk
            $table->string('kind', 30)->nullable();
            $table->string('file_hash', 64);
            $table->date('purge_after')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_evidence');
    }
};
