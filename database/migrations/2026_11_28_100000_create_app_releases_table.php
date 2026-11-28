<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App versions (the dashboard's App versions page, `GET /v1/app/version`): one row per store.
 *
 * - `latest_version`  what the store has now. Below it, the app suggests an update.
 * - `minimum_version` the oldest version still allowed. Below it, the update is required.
 * - `force_update`    when on, everything below `latest_version` is required too.
 * - `notified_version` the version users were last told about, so a release is announced once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table) {
            $table->string('platform', 10)->primary();
            $table->string('latest_version', 20);
            $table->string('minimum_version', 20);
            $table->string('store_url', 500);
            $table->boolean('force_update')->default(false);
            $table->text('notes_ar')->nullable();
            $table->text('notes_en')->nullable();
            $table->string('notified_version', 20)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->unsignedInteger('notified_count')->default(0);
            $table->foreignUlid('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
