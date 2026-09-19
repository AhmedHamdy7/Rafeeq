<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_consents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // RESTRICT (Laravel default, no cascade): consent records are
            // permanent legal evidence — see ERD §18 retention policy.
            $table->foreignUlid('user_id')->constrained('users');
            $table->string('document_type', 30); // terms|privacy|marketing
            $table->string('document_version', 20);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('source', 30)->nullable(); // registration|settings|forced_reaccept
            $table->timestamps();

            $table->index(['user_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};
