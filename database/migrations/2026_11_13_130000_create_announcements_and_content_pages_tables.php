<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements (Z01) and Content (J01).
 *
 * `created_by` / `updated_by` name the staff member without a foreign key, on purpose: the
 * production database's `admin_users.id` has a different collation from new tables, and a
 * constraint that cannot be created leaves a half-made table behind (see the admin
 * invitations migration). The audit log is where "who did it" is enforced. Dropped first
 * for the same reason, so a re-run after a failed deploy starts clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('announcements');
        Schema::create('announcements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('audience', 20); // everyone|passengers|drivers
            $table->string('title_ar', 120);
            $table->string('title_en', 120);
            $table->text('body_ar');
            $table->text('body_en');
            $table->string('link', 40)->nullable(); // a screen in the app, from a fixed list
            $table->dateTime('scheduled_for')->nullable();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->unsignedInteger('reach')->default(0);
            $table->ulid('created_by')->nullable()->index();
            $table->timestamps();

            $table->index(['sent_at', 'scheduled_for']);
        });

        Schema::dropIfExists('content_pages');
        Schema::create('content_pages', function (Blueprint $table) {
            $table->string('key', 40)->primary(); // help|support_contact|terms|guidelines
            $table->text('body_ar');
            $table->text('body_en');
            $table->unsignedInteger('version')->default(1);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
        Schema::dropIfExists('announcements');
    }
};
