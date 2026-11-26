<?php

namespace App\Domains\Geo\Models;

use Database\Factories\PlacePinPickFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'label', 'lat', 'lng', 'area_id', 'roles', 'uses', 'last_used_at'])]
class PlacePinPick extends Model
{
    /** @use HasFactory<PlacePinPickFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'roles' => 'integer',
            'uses' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }
}
