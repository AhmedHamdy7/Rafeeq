<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dateTime('dispute_resolved_at')->nullable()->after('dispute_resolved_by');
            $table->string('dispute_note', 255)->nullable()->after('dispute_resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn(['dispute_resolved_at', 'dispute_note']);
        });
    }
};
