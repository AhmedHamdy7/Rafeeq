<?php

namespace App\Domains\Geo\Models;

use App\Domains\Geo\Enums\PlaceType;
use App\Domains\Geo\Support\NearPlace;
use App\Domains\Geo\Support\PlaceNameMatch;
use App\Domains\Shared\Casts\SpatialPoint;
use App\Domains\Shared\ValueObjects\Distance;
use Database\Factories\PlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['name', 'name_ar', 'aliases', 'type', 'point', 'lat', 'lng', 'radius_metres', 'city', 'district', 'google_place_id', 'is_public'])]
class Place extends Model
{
    /** @use HasFactory<PlaceFactory> */
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (Place $place): void {
            if (trim((string) $place->name_ar) === '') {
                throw new InvalidArgumentException('A place needs an Arabic name.');
            }

            $place->search_name = PlaceNameMatch::searchName([$place->name, $place->name_ar, ...($place->aliases ?? [])]);
        });

        static::saved(fn () => NearPlace::forget());
        static::deleted(fn () => NearPlace::forget());
    }

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'radius_metres' => 'integer',
            'type' => PlaceType::class,
            'point' => SpatialPoint::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'is_public' => 'boolean',
            'usage_count' => 'integer',
        ];
    }

    public function areaRadius(): ?Distance
    {
        if (! $this->type->isArea()) {
            return null;
        }

        return Distance::fromMetres(
            $this->radius_metres ?? (int) config('rafeeq.geo.area_radius_metres.'.$this->type->value),
        );
    }

    public function localName(): string
    {
        return app()->getLocale() === 'en' ? $this->name : $this->name_ar;
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
