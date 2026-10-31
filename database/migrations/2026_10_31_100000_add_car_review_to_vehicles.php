<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the admin decides about a car at verification (F2-6, PROF-26, PROF-28, ADM-01).
 *
 * 🔴 `approved_seats` is nullable on purpose (binding standard #36): null means "nobody has reviewed
 * this car's seat belts yet", which is a different fact from any number. A car without it cannot
 * carry a published commute, and the seat price cannot be divided by it.
 *
 * It is NOT `seats`. `seats` is what the driver typed on the vehicle form (PROF-30) — an entry for
 * the reviewer to check. `approved_seats` is the reviewer's answer and the only number that limits
 * a commute or divides the price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedTinyInteger('approved_seats')->nullable()->after('seats');
            // VehicleTier: standard|comfort. Standard until an admin says otherwise.
            $table->string('tier', 16)->default('standard')->after('approved_seats');
            $table->boolean('has_ac')->default(false)->after('tier');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['approved_seats', 'tier', 'has_ac']);
        });
    }
};
