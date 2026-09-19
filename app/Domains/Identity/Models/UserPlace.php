<?php

namespace App\Domains\Identity\Models;

use App\Domains\Geo\Models\Place;
use App\Domains\Identity\Enums\UserPlaceLabel;
use App\Domains\Shared\Casts\SpatialPoint;
use Database\Factories\UserPlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Home screen's "to work / to home" direction sheet is built entirely on
 * this table (ERD §3).
 */
#[Fillable([
    'user_id', 'label', 'display_name', 'place_id', 'point', 'lat', 'lng',
    'address', 'is_default_origin', 'blur_radius_meters',
])]
#[Hidden(['point', 'lat', 'lng'])] // never exposed exactly — see pitfall #22
class UserPlace extends Model
{
    /** @use HasFactory<UserPlaceFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'label' => UserPlaceLabel::class,
            'point' => SpatialPoint::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'is_default_origin' => 'boolean',
            'blur_radius_meters' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}
