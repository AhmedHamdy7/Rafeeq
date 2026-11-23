<?php

namespace App\Domains\Geo\Support;

final readonly class PointArea
{
    public function __construct(
        public ?string $googlePlaceId = null,
        public ?string $areaPlaceId = null,
        public ?string $areaName = null,
        public ?string $areaCity = null,
    ) {}

    /**
     * Accepts both shapes the app sends: `area: {placeId, name, city}` in a body, and
     * `areaId`, `areaName`, `areaCity` in a search query.
     *
     * @param  array<string, mixed>|null  $point
     */
    public static function from(?array $point): self
    {
        $area = is_array($point['area'] ?? null) ? $point['area'] : [];

        return new self(
            googlePlaceId: self::text($point['googlePlaceId'] ?? null),
            areaPlaceId: self::text($area['placeId'] ?? $point['areaId'] ?? null),
            areaName: self::text($area['name'] ?? $point['areaName'] ?? null),
            areaCity: self::text($area['city'] ?? $point['areaCity'] ?? null),
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(string $prefix): array
    {
        return [
            "{$prefix}.googlePlaceId" => ['nullable', 'string', 'max:255'],
            "{$prefix}.area" => ['nullable', 'array'],
            "{$prefix}.area.placeId" => ['nullable', 'string', 'max:255'],
            "{$prefix}.area.name" => ['nullable', 'string', 'max:150'],
            "{$prefix}.area.city" => ['nullable', 'string', 'max:150'],
            "{$prefix}.areaId" => ['nullable', 'string', 'max:255'],
            "{$prefix}.areaName" => ['nullable', 'string', 'max:150'],
            "{$prefix}.areaCity" => ['nullable', 'string', 'max:150'],
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
