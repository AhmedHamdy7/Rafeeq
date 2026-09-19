<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every configurable number in the system lives here (Bible §4, group
     * ⑭) — OTP expiry, grace periods, platform fee %, debt cap, matching
     * radius, etc. No magic numbers hard-coded in application code.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('setting_key', 100)->primary();
            $table->json('setting_value');
            $table->string('value_type', 20)->nullable();
            $table->string('description', 255)->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
