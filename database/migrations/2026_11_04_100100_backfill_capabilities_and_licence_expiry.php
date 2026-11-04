<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->whereNotNull('users.initial_role_selected_at')
            ->select('users.id', 'users.registered_role', 'driver_profiles.user_id as driver_profile_id')
            ->orderBy('users.id')
            ->chunk(500, function ($users): void {
                $rows = [];

                foreach ($users as $user) {
                    $role = $user->registered_role ?? 'passenger';

                    if (in_array($role, ['passenger', 'both'], true)) {
                        $rows[] = $this->row($user->id, 'passenger');
                    }

                    if (in_array($role, ['driver', 'both'], true) || $user->driver_profile_id !== null) {
                        $rows[] = $this->row($user->id, 'driver');
                    }
                }

                DB::table('user_capabilities')->insertOrIgnore($rows);
            });

        DB::statement(<<<'SQL'
            UPDATE user_verifications
            JOIN driver_profiles ON driver_profiles.user_id = user_verifications.user_id
            SET user_verifications.expires_at = driver_profiles.licence_expiry,
                user_verifications.updated_at = user_verifications.updated_at
            WHERE user_verifications.type = 'driving_licence'
              AND user_verifications.status = 'approved'
              AND user_verifications.expires_at IS NULL
              AND driver_profiles.licence_expiry IS NOT NULL
        SQL);
    }

    public function down(): void {}

    /**
     * @return array<string, mixed>
     */
    private function row(string $userId, string $capability): array
    {
        return [
            'id' => (string) Str::ulid(),
            'user_id' => $userId,
            'capability' => $capability,
            'status' => 'active',
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
};
