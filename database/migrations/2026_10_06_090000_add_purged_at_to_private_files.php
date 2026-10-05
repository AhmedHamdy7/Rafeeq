<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a private file's bytes were destroyed under its retention date (`files:purge-expired`).
 *
 * The ROW stays: it records that a file existed, what kind it was and — for evidence — the hash
 * that proves what it contained. Only the bytes go. Without this column the purge would have to
 * re-check the disk for every expired row every night to find out it had already done its job.
 */
return new class extends Migration
{
    private const array TABLES = ['identity_documents', 'vehicle_documents', 'incident_evidence'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dateTime('purged_at')->nullable()->after('purge_after');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('purged_at');
            });
        }
    }
};
