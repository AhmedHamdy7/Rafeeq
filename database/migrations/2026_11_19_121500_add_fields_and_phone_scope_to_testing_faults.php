<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('testing_faults', function (Blueprint $table) {
            $table->json('fields')->nullable()->after('retry_after_seconds');
            $table->ulid('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('testing_faults', function (Blueprint $table) {
            $table->dropColumn('fields');
        });
    }
};
