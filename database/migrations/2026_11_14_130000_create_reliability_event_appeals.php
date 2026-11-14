<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reliability_events', function (Blueprint $table) {
            $table->string('cause', 40)->nullable()->after('commute_offer_id');
            $table->ulid('group_id')->nullable()->after('cause');
            $table->string('cancel_reason', 30)->nullable()->after('group_id');

            $table->index('group_id');
        });

        Schema::create('reliability_event_appeals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reliability_event_id')->constrained('reliability_events')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('round');
            $table->string('reason', 1000);
            $table->unsignedSmallInteger('evidence_count')->default(0);
            $table->string('resolution', 20)->nullable();
            $table->string('decline_reason_code', 40)->nullable();
            $table->ulid('resolved_by_admin_id')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['reliability_event_id', 'round']);
            $table->index('user_id');
        });

        Schema::create('reliability_appeal_evidence', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reliability_event_id')->constrained('reliability_events')->cascadeOnDelete();
            $table->string('file_path', 255);
            $table->string('kind', 30)->nullable();
            $table->string('file_hash', 64);
            $table->date('purge_after')->nullable();
            $table->dateTime('purged_at')->nullable();
            $table->timestamps();
        });

        DB::table('reliability_events')
            ->whereNotNull('appealed_at')
            ->orderBy('id')
            ->chunkById(200, function ($events): void {
                foreach ($events as $event) {
                    $overturned = $event->reversed_at !== null;
                    $upheld = ! $overturned && $event->appeal_declined_at !== null;

                    DB::table('reliability_event_appeals')->insert([
                        'id' => (string) Str::ulid(),
                        'reliability_event_id' => $event->id,
                        'user_id' => $event->user_id,
                        'round' => 1,
                        'reason' => (string) $event->appeal_reason,
                        'evidence_count' => 0,
                        'resolution' => $overturned ? 'overturned' : ($upheld ? 'upheld' : null),
                        'decline_reason_code' => $upheld ? 'other' : null,
                        'resolved_by_admin_id' => $overturned || $upheld ? $event->reversed_by : null,
                        'resolved_at' => $overturned ? $event->reversed_at : ($upheld ? $event->appeal_declined_at : null),
                        'resolution_note' => $overturned || $upheld ? $event->reversed_reason : null,
                        'created_at' => $event->appealed_at,
                        'updated_at' => $event->appealed_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('reliability_appeal_evidence');
        Schema::dropIfExists('reliability_event_appeals');

        Schema::table('reliability_events', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropColumn(['cause', 'group_id', 'cancel_reason']);
        });
    }
};
