<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $women = DB::table('users')->where('gender', 'woman')->select('id');

        DB::table('commute_demands')
            ->where('audience_preference', 'women_only')
            ->where('women_only', false)
            ->whereIn('passenger_user_id', $women)
            ->update(['women_only' => true]);

        DB::table('commute_demands')
            ->where('audience_preference', 'women_only')
            ->where('women_only', false)
            ->update(['audience_preference' => 'any_verified']);

        DB::table('commute_demands')
            ->where('women_only', true)
            ->where('audience_preference', '!=', 'women_only')
            ->update(['audience_preference' => 'women_only']);
    }

    public function down(): void {}
};
