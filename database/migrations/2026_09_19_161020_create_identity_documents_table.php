<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_verification_id')->constrained('user_verifications')->cascadeOnDelete();
            $table->string('kind', 40); // national_id_front|national_id_back|selfie|licence_front|badge
            $table->string('file_path', 255); // PRIVATE disk only — never a public URL
            $table->string('file_hash', 64)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->text('ocr_payload')->nullable(); // encrypted at rest
            $table->string('virus_scan_status', 20)->default('pending'); // pending|clean|infected
            $table->timestamp('expires_at')->nullable();
            $table->date('purge_after')->nullable(); // enforced by a daily job
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_documents');
    }
};
