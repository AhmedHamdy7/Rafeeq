<?php

use App\Domains\Analytics\Models\DemandHeatmapCell;
use Illuminate\Database\QueryException;

it('rejects a duplicate cell for the same geohash, date and hour bucket', function () {
    $cell = DemandHeatmapCell::factory()->create();

    DemandHeatmapCell::factory()->create([
        'cell_geohash' => $cell->cell_geohash,
        'cell_date' => $cell->cell_date,
        'hour_bucket' => $cell->hour_bucket,
    ]);
})->throws(QueryException::class);

it('is only displayable above the minimum anonymization threshold', function () {
    $tooFew = DemandHeatmapCell::factory()->create(['hour_bucket' => 6, 'demand_count' => 3]);
    $enough = DemandHeatmapCell::factory()->create(['hour_bucket' => 7, 'demand_count' => 5]);

    expect($tooFew->isDisplayable())->toBeFalse()
        ->and($enough->isDisplayable())->toBeTrue();
});
