<?php

namespace App\Domains\Geo\Models;

use Database\Factories\CorridorStatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['corridor_id', 'stat_date', 'drivers', 'seekers', 'fill_pct'])]
class CorridorStat extends Model
{
    /** @use HasFactory<CorridorStatFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'drivers' => 'integer',
            'seekers' => 'integer',
            'fill_pct' => 'decimal:2',
        ];
    }

    public function corridor(): BelongsTo
    {
        return $this->belongsTo(Corridor::class);
    }
}
