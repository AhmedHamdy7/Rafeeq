<?php

namespace App\Domains\Geo\Models;

use App\Domains\Geo\Enums\PlaceType;
use App\Domains\Shared\Casts\SpatialPoint;
use Database\Factories\PlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'name_ar', 'type', 'point', 'lat', 'lng', 'city', 'district', 'google_place_id', 'is_public'])]
class Place extends Model
{
    /** @use HasFactory<PlaceFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'type' => PlaceType::class,
            'point' => SpatialPoint::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'is_public' => 'boolean',
            'usage_count' => 'integer',
        ];
    }

    public function originOfCorridors(): HasMany
    {
        return $this->hasMany(Corridor::class, 'origin_place_id');
    }

    public function destinationOfCorridors(): HasMany
    {
        return $this->hasMany(Corridor::class, 'destination_place_id');
    }
}
