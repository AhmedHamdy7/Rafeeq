<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('type', 30); // registration|insurance|inspection
            $table->string('file_path', 255); // private disk only
            $table->string('file_hash', 64)->nullable();
            $table->date('expires_at')->nullable();
            $table->string('verification_status', 20)->default('pending');
            $table->date('purge_after')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
    }
};
