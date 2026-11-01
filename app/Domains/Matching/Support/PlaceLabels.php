<?php

namespace App\Domains\Matching\Support;

use App\Domains\Geo\Actions\SearchPlacesAction;
use App\Domains\Shared\ValueObjects\Coordinate;

/**
 * What the two ends of a search or a demand are called (DISCOVER-8, -9, -12).
 *
 * Coordinates are what the search runs on; this is what a person reads — "New Cairo → Smart
 * Village" on a recent search, a demand card, and Home's "Matches 'New Cairo → Smart Village'".
 * Without it every one of those screens shows two pairs of decimals, and geocoding on the phone is
 * not an option.
 *
 * 🔴 Never part of the question. Two searches that differ only in a label are the same search,
 * so none of this enters {@see SearchCriteria::signature()}.
 */
final readonly class PlaceLabels
{
    public function __construct(
        public ?string $originLabel = null,
        public ?string $originPlaceId = null,
        public ?string $destinationLabel = null,
        public ?string $destinationPlaceId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated: `origin.label`, `origin.placeId`, and the same for `destination`
     */
    public static function fromInput(array $input): self
    {
        return new self(
            originLabel: self::text($input['origin']['label'] ?? null),
            originPlaceId: self::text($input['origin']['placeId'] ?? null),
            destinationLabel: self::text($input['destination']['label'] ?? null),
            destinationPlaceId: self::text($input['destination']['placeId'] ?? null),
        );
    }

    /**
     * The same, with a missing label filled in from the nearest catalogue place (DISCOVER-12).
     *
     * A label she gave always wins — it is what she calls the place. Only an end that arrived as
     * bare coordinates is named, and only after somewhere close enough to be honest about it; past
     * that it stays null rather than naming a place she is not at.
     */
    public function resolved(SearchPlacesAction $places, Coordinate $origin, Coordinate $destination): self
    {
        return new self(
            originLabel: $this->originLabel ?? $places->nearest($origin)?->name,
            originPlaceId: $this->originPlaceId,
            destinationLabel: $this->destinationLabel ?? $places->nearest($destination)?->name,
            destinationPlaceId: $this->destinationPlaceId,
        );
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
