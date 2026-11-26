<?php

namespace App\Domains\Geo\Models;

use App\Domains\Geo\Enums\PlaceSuggestionKind;
use App\Domains\Geo\Support\PlaceNameMatch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'kind', 'label', 'label_ar', 'search_text', 'lat', 'lng', 'google_place_id', 'area_id',
    'roles', 'uses', 'last_used_at', 'coords_fetched_at',
])]
class PlaceSuggestion extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'kind' => PlaceSuggestionKind::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'roles' => 'integer',
            'uses' => 'integer',
            'last_used_at' => 'datetime',
            'coords_fetched_at' => 'datetime',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id', 'google_place_id');
    }

    public function localLabel(?string $locale = null): ?string
    {
        return ($locale ?? app()->getLocale()) === 'ar'
            ? ($this->label_ar ?? $this->label)
            : ($this->label ?? $this->label_ar);
    }

    public function refreshSearchText(): void
    {
        $this->search_text = mb_substr(PlaceNameMatch::searchName([
            $this->label,
            $this->label_ar,
            $this->area?->name_en,
            $this->area?->name_ar,
        ]), 0, 1000);
    }
}
