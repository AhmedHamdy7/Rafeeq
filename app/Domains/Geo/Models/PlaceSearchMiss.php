<?php

namespace App\Domains\Geo\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'query', 'normalised_query', 'lat', 'lng'])]
class PlaceSearchMiss extends Model
{
    use HasUlids;

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
