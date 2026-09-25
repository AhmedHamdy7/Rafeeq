<?php

namespace App\Domains\Matching\Support;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Shared\ValueObjects\WalkTime;

/**
 * What a passenger asked for, as one value.
 *
 * Kept as an object rather than passed around as an array so the pipeline and
 * the scorer cannot disagree about what a field means, and so the signature
 * that keys the result cache is derived from the same thing the search ran on.
 */
final readonly class SearchCriteria
{
    /**
     * @param  array<int, string>  $requiredRules  house rules the passenger wants,
     *                                             scored as comfort rather than filtered on — a quiet commute is a
     *                                             preference, and excluding on it would hide otherwise good matches
     */
    public function __construct(
        public Coordinate $origin,
        public Coordinate $destination,
        public DaysMask $days,
        public string $arrivalWindowStart,
        public string $arrivalWindowEnd,
        public WalkTime $maxWalk,
        public int $maxDetourMinutes,
        public int $seatsNeeded = 1,
        public ?CommuteAudience $audiencePreference = null,
        public ?int $budgetPerSeatPiastres = null,
        public array $requiredRules = [],
    ) {}

    /**
     * @param  array<string, mixed>  $input  already validated upstream
     */
    public static function fromArray(array $input): self
    {
        return new self(
            origin: new Coordinate((float) $input['origin']['lat'], (float) $input['origin']['lng']),
            destination: new Coordinate((float) $input['destination']['lat'], (float) $input['destination']['lng']),
            days: DaysMask::fromBits((int) $input['daysMask']),
            arrivalWindowStart: $input['arrivalWindowStart'],
            arrivalWindowEnd: $input['arrivalWindowEnd'],
            maxWalk: WalkTime::fromMinutes((int) $input['maxWalkMinutes']),
            maxDetourMinutes: (int) $input['maxDetourMinutes'],
            seatsNeeded: (int) ($input['seatsNeeded'] ?? 1),
            audiencePreference: isset($input['audiencePreference'])
                ? CommuteAudience::from($input['audiencePreference'])
                : null,
            budgetPerSeatPiastres: isset($input['budgetPerSeatPiastres'])
                ? (int) $input['budgetPerSeatPiastres']
                : null,
            requiredRules: $input['rules'] ?? [],
        );
    }

    /**
     * The cache key for this search.
     *
     * Coordinates are rounded to three decimals (~110m) before hashing. Exact
     * floats would make every search a cache miss — a passenger who nudges the
     * map pin by a metre is asking the same question, and re-running the whole
     * pipeline for that is work nobody asked for.
     *
     * The user id is part of it because the hard filters depend on who is
     * asking: two people with identical criteria see different results if one is
     * blocked by a driver the other is not, and sharing a cache row between them
     * would leak that.
     */
    public function signature(string $userId): string
    {
        return hash('sha256', implode('|', [
            $userId,
            round($this->origin->lat, 3),
            round($this->origin->lng, 3),
            round($this->destination->lat, 3),
            round($this->destination->lng, 3),
            $this->days->value,
            $this->arrivalWindowStart,
            $this->arrivalWindowEnd,
            $this->maxWalk->minutes,
            $this->maxDetourMinutes,
            $this->seatsNeeded,
            $this->audiencePreference?->value ?? 'any',
            $this->budgetPerSeatPiastres ?? 'none',
            implode(',', $this->requiredRules),
        ]));
    }
}
