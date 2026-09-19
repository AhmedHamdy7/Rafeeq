<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('passenger_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('commute_offer_id')->constrained('commute_offers')->cascadeOnDelete();
            $table->foreignUlid('scheduled_trip_id')->nullable()->constrained('scheduled_trips')->nullOnDelete(); // set for trial requests
            $table->string('commitment', 20); // trial | recurring
            $table->unsignedTinyInteger('requested_days_mask')->nullable(); // for recurring
            $table->unsignedTinyInteger('seats')->default(1);
            $table->string('meeting_preference', 20); // gate|street|landmark|custom
            $table->foreignUlid('custom_pickup_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->text('intro_message')->nullable(); // filtered for abuse/phone numbers at the Action layer
            $table->dateTime('agreed_to_rules_at'); // MANDATORY — checked before accepting the request
            $table->string('payment_type', 10); // cash | online
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|waitlisted|withdrawn|expired
            $table->smallInteger('waitlist_position')->nullable();
            $table->foreignUlid('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('responded_at')->nullable();
            $table->string('response_note', 255)->nullable();
            $table->dateTime('expires_at')->nullable(); // 48h then auto-expire
            $table->timestamps();

            // 🔴 Bible §4, group ⑦ — no fluent partial-index helper exists, so
            // "only one pending/approved request per passenger per offer" is
            // enforced the same way as users.phone_e164_active: a generated
            // column that collapses to NULL for every other status.
            $table->string('active_request_key', 80)
                ->nullable()
                ->virtualAs("IF(status IN ('pending','approved'), CONCAT(passenger_user_id, ':', commute_offer_id), NULL)");
            $table->unique('active_request_key');

            $table->index(['commute_offer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_requests');
    }
};
