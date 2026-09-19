<?php

namespace App\Domains\Trip\Models;

use Database\Factories\TripLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['trip_session_id', 'lat', 'lng', 'accuracy_meters', 'speed_kmh', 'recorded_at', 'purge_after'])]
class TripLocation extends Model
{
    /** @use HasFactory<TripLocationFactory> */
    use HasFactory, HasUlids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'accuracy_meters' => 'integer',
            'speed_kmh' => 'integer',
            'recorded_at' => 'datetime',
            'purge_after' => 'date',
        ];
    }

    public function tripSession(): BelongsTo
    {
        return $this->belongsTo(TripSession::class);
    }
}
