<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE user_verifications
            JOIN driver_profiles ON driver_profiles.user_id = user_verifications.user_id
            SET user_verifications.status = 'approved',
                user_verifications.expires_at = driver_profiles.licence_expiry,
                user_verifications.reviewed_at = driver_profiles.verified_at,
                user_verifications.reviewed_by = driver_profiles.reviewer_id,
                user_verifications.updated_at = user_verifications.updated_at
            WHERE user_verifications.type = 'driving_licence'
              AND user_verifications.status NOT IN ('approved', 'action_needed')
              AND driver_profiles.status = 'approved'
        SQL);
    }

    public function down(): void {}
};
