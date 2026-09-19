<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('phone_e164', 20);
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('full_name', 100)->nullable();
            $table->string('public_first_name', 50)->nullable();
            $table->string('profile_photo_path', 255)->nullable();
            $table->string('gender', 20); // woman | man | prefer_not_to_say — hard filter, never in API output
            $table->date('date_of_birth')->nullable();
            $table->string('email', 150)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('account_status', 30)->default('active'); // active|suspended|pending_deletion|deleted
            $table->string('suspension_reason', 255)->nullable();
            $table->string('profile_status', 30)->default('not_started'); // not_started|basic_complete
            $table->string('org_type', 20)->nullable(); // work | university
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('registered_role', 20); // driver | passenger | both
            $table->string('preferred_language', 5)->default('ar');
            $table->unsignedTinyInteger('trust_level')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Pitfall #60: a plain UNIQUE(phone_e164) breaks re-registration
            // after a soft delete, but UNIQUE(phone_e164, deleted_at) does NOT
            // actually block two active (deleted_at IS NULL) rows in MySQL/
            // MariaDB — NULL is never equal to NULL in a composite unique
            // index. Verified empirically against this project's MariaDB
            // 10.4. The generated-column trick used below (same pattern the
            // Bible documents for `vehicles.is_active`) is the correct fix:
            // it collapses to NULL for every soft-deleted row (MySQL allows
            // unlimited NULLs in a unique index) and to the real phone number
            // only while the account is active.
            $table->string('phone_e164_active', 20)
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, phone_e164, NULL)');
            $table->unique('phone_e164_active');

            $table->index(['account_status', 'created_at']);
            $table->index(['gender', 'account_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
