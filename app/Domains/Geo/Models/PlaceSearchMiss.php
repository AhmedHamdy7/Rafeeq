<?php

namespace App\Domains\Geo\Models;

use Database\Factories\PlaceSearchMissFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'query', 'normalised_query', 'lat', 'lng'])]
class PlaceSearchMiss extends Model
{
    /** @use HasFactory<PlaceSearchMissFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'created_at' => 'datetime',
        ];
    }
}
