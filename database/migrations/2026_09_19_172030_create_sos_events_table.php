<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sos_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('safety_event_id')->constrained('safety_events');
            $table->smallInteger('countdown_seconds'); // guards against an accidental tap
            $table->dateTime('cancelled_at')->nullable();
            $table->boolean('is_discreet')->default(false); // SILENT — no sound, no vibration
            $table->dateTime('first_touch_at')->nullable(); // THE ops response-time metric
            $table->foreignUlid('responder_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('resolution', 30)->nullable(); // false_alarm|resolved|escalated_police
            $table->geography('location_at_trigger', subtype: 'point', srid: 4326)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sos_events');
    }
};
