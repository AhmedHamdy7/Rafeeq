<?php

namespace App\Domains\Analytics\Models;

use Database\Factories\DemandHeatmapCellFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Aggregated and anonymized by design — never display a cell below the
 * minimum count, or individuals become identifiable (Bible §4, group ⑭).
 */
#[Fillable(['cell_geohash', 'cell_date', 'hour_bucket', 'demand_count', 'supply_count'])]
class DemandHeatmapCell extends Model
{
    /** @use HasFactory<DemandHeatmapCellFactory> */
    use HasFactory, HasUlids;

    private const int MINIMUM_DISPLAYABLE_COUNT = 5;

    protected function casts(): array
    {
        return [
            'cell_date' => 'date',
            'hour_bucket' => 'integer',
            'demand_count' => 'integer',
            'supply_count' => 'integer',
        ];
    }

    public function isDisplayable(): bool
    {
        return $this->demand_count >= self::MINIMUM_DISPLAYABLE_COUNT;
    }
}
