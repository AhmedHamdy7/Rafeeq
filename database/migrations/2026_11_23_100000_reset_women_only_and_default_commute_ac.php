<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('commute_offers')
            ->where('audience', 'women_only')
            ->whereNotIn('driver_profile_id', DB::table('users')
                ->where('gender', 'woman')
                ->whereExists(fn ($query) => $query->selectRaw(1)
                    ->from('user_verifications')
                    ->whereColumn('user_verifications.user_id', 'users.id')
                    ->where('user_verifications.type', 'government_id')
                    ->where('user_verifications.status', 'approved'))
                ->select('id'))
            ->update(['audience' => 'any_verified', 'updated_at' => now()]);

        DB::table('commute_offers')
            ->leftJoin('vehicles', 'vehicles.id', '=', 'commute_offers.vehicle_id')
            ->whereNotExists(fn ($query) => $query->selectRaw(1)
                ->from('commute_rules')
                ->whereColumn('commute_rules.commute_offer_id', 'commute_offers.id')
                ->where('commute_rules.rule_key', 'ac'))
            ->get(['commute_offers.id', 'vehicles.has_ac'])
            ->chunk(500)
            ->each(fn ($offers) => DB::table('commute_rules')->insert($offers->map(fn ($offer) => [
                'id' => (string) Str::ulid(),
                'commute_offer_id' => $offer->id,
                'rule_key' => 'ac',
                'rule_value' => (bool) $offer->has_ac,
                'created_at' => now(),
                'updated_at' => now(),
            ])->values()->all()));
    }

    public function down(): void {}
};
