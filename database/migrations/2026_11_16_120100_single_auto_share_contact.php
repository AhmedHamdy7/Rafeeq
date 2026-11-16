<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_share_messages', function (Blueprint $table) {
            $table->string('kind', 24)->default('auto_share')->after('id');
            $table->text('body')->nullable();
        });

        Schema::table('auto_share_messages', function (Blueprint $table) {
            $table->foreignUlid('trip_session_id')->nullable()->change();
        });

        $chosen = [];

        DB::table('emergency_contacts')
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'auto_share_trips'])
            ->each(function ($contact) use (&$chosen): void {
                if ($contact->auto_share_trips && ! isset($chosen[$contact->user_id])) {
                    $chosen[$contact->user_id] = $contact->id;
                }
            });

        DB::table('emergency_contacts')->update(['auto_share_trips' => false]);

        $switchedOn = DB::table('users')->where('auto_share_trips', true)->pluck('id');

        foreach ($switchedOn as $userId) {
            if (isset($chosen[$userId])) {
                continue;
            }

            $first = DB::table('emergency_contacts')
                ->where('user_id', $userId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->value('id');

            if ($first !== null) {
                $chosen[$userId] = $first;
            }
        }

        if ($chosen !== []) {
            DB::table('emergency_contacts')->whereIn('id', array_values($chosen))->update(['auto_share_trips' => true]);
        }
    }

    public function down(): void
    {
        DB::table('auto_share_messages')->whereNull('trip_session_id')->delete();

        Schema::table('auto_share_messages', function (Blueprint $table) {
            $table->dropColumn(['kind', 'body']);
        });
    }
};
