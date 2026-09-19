<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_point_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('seat_request_id')->nullable()->constrained('seat_requests')->cascadeOnDelete(); // nullable — new joiner
            $table->foreignUlid('group_member_id')->nullable()->constrained('group_members')->cascadeOnDelete(); // nullable — existing member
            $table->foreignUlid('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->geography('proposed_point', subtype: 'point', srid: 4326);
            $table->string('proposed_label', 150)->nullable();
            $table->decimal('added_minutes', 4, 1); // computed by us, never claimed by the requester
            $table->decimal('added_km', 5, 2);
            $table->string('status', 30)->default('pending'); // pending|approved|suggested_alternative|rejected
            $table->foreignUlid('alternative_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->string('effective_from', 20)->nullable(); // next_trip | specific_date
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_point_requests');
    }
};
