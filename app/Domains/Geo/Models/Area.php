<?php

namespace App\Domains\Geo\Models;

use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['google_place_id', 'name_en', 'name_ar', 'city_en', 'city_ar', 'lat', 'lng', 'viewport', 'group_id'])]
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use HasFactory;

    protected $primaryKey = 'google_place_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'viewport' => 'array',
        ];
    }

    public function name(?string $locale = null): ?string
    {
        return self::pick($this->name_en, $this->name_ar, $locale);
    }

    public function city(?string $locale = null): ?string
    {
        return self::pick($this->city_en, $this->city_ar, $locale);
    }

    /**
     * @return array{placeId: string, name: string|null, city: string|null}
     */
    public function toPoint(): array
    {
        return ['placeId' => $this->google_place_id, 'name' => $this->name(), 'city' => $this->city()];
    }

    public static function nameOf(?string $id): ?string
    {
        return $id === null ? null : self::query()->find($id)?->name();
    }

    /**
     * @return array{placeId: string, name: string|null, city: string|null}|null
     */
    public static function pointOf(?string $id): ?array
    {
        return $id === null ? null : self::query()->find($id)?->toPoint();
    }

    private static function pick(?string $en, ?string $ar, ?string $locale): ?string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? ($ar ?? $en) : ($en ?? $ar);
    }
}
