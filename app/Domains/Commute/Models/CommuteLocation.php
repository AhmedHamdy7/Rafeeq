<?php

namespace App\Domains\Commute\Models;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Geo\Models\Place;
use App\Domains\Shared\Casts\SpatialPoint;
use Database\Factories\CommuteLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['commute_offer_id', 'place_id', 'type', 'point', 'lat', 'lng', 'address', 'sequence', 'is_exact'])]
class CommuteLocation extends Model
{
    /** @use HasFactory<CommuteLocationFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'type' => CommuteLocationType::class,
            'point' => SpatialPoint::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'sequence' => 'integer',
            'is_exact' => 'boolean',
        ];
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}
