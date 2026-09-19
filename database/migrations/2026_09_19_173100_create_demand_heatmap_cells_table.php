<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aggregated and anonymized by design (Bible §4, group ⑭) — a minimum
     * count (≥5) must be enforced before display, or the heatmap
     * de-anonymizes individuals. No FKs on purpose.
     */
    public function up(): void
    {
        Schema::create('demand_heatmap_cells', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('cell_geohash', 20); // precision 6 ≈ 1.2km
            $table->date('cell_date');
            $table->unsignedTinyInteger('hour_bucket');
            $table->unsignedInteger('demand_count')->default(0); // min 5 before display
            $table->unsignedInteger('supply_count')->default(0);
            $table->timestamps();

            $table->unique(['cell_geohash', 'cell_date', 'hour_bucket'], 'demand_heatmap_cells_unique_bucket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_heatmap_cells');
    }
};
