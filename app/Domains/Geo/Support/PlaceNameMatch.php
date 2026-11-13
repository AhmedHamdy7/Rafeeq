<?php

namespace App\Domains\Geo\Support;

use App\Domains\Geo\Models\Place;
use Illuminate\Database\Eloquent\Builder;

final readonly class PlaceNameMatch
{
    public const int NAME_STARTS = 0;

    public const int NAME_CONTAINS = 1;

    public const int AREA_ONLY = 2;

    /**
     * @param  list<string>  $areas
     */
    private function __construct(
        private string $needle,
        private array $areas,
    ) {}

    public static function for(string $term): self
    {
        $needle = self::normalise($term);

        return new self($needle, self::areasCalledInEnglish($needle));
    }

    /**
     * @param  Builder<Place>  $query
     */
    public function constrain(Builder $query): void
    {
        $pattern = '%'.self::likePattern($this->needle).'%';

        $query->where(function (Builder $query) use ($pattern): void {
            $query->where('name', 'like', $pattern)
                ->orWhere('name_ar', 'like', $pattern)
                ->orWhere('district', 'like', $pattern)
                ->orWhere('city', 'like', $pattern);

            if ($this->areas !== []) {
                $query->orWhereIn('district', $this->areas)
                    ->orWhereIn('city', $this->areas);
            }
        });
    }

    public function rank(Place $place): ?int
    {
        $names = [self::normalise($place->name), self::normalise($place->name_ar)];

        foreach ($names as $name) {
            if (str_starts_with($name, $this->needle)) {
                return self::NAME_STARTS;
            }
        }

        foreach ($names as $name) {
            if (str_contains($name, $this->needle)) {
                return self::NAME_CONTAINS;
            }
        }

        foreach ([$place->district, $place->city] as $area) {
            if ($area === null || $area === '') {
                continue;
            }

            if (str_contains(self::normalise($area), $this->needle) || in_array($area, $this->areas, true)) {
                return self::AREA_ONLY;
            }
        }

        return null;
    }

    public static function normalise(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));
        $text = (string) preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
    }

    private static function likePattern(string $needle): string
    {
        $escaped = strtr($needle, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);

        return strtr($escaped, ['ا' => '_', 'ه' => '_', 'ي' => '_']);
    }

    /**
     * @return list<string>
     */
    private static function areasCalledInEnglish(string $needle): array
    {
        if (preg_match('/[a-z]/', $needle) !== 1) {
            return [];
        }

        $public = fn () => Place::query()->where('is_public', true)->distinct();

        return $public()->pluck('district')
            ->merge($public()->pluck('city'))
            ->filter(fn (?string $area): bool => $area !== null && $area !== '')
            ->unique()
            ->filter(function (string $area) use ($needle): bool {
                $english = __($area, [], 'en');

                return $english !== $area && str_contains(self::normalise($english), $needle);
            })
            ->values()
            ->all();
    }
}
