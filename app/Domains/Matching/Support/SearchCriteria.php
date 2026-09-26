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
        /**
         * 🔴 A MONTHLY ceiling, not a per-ride one.
         *
         * This was `budgetPerSeatPiastres` and it was wrong in a way that silently
         * disabled a scoring component. Three sources say monthly — the column
         * (`budget_monthly_piastres`), the ERD, and screen 18's own stepper, which runs
         * from 800 to 3000 EGP in steps of 100 where a per-ride price is 70 to 95. So a
         * passenger who filled the screen in as designed sent ~1600, it was compared
         * against a single trip's price, every commute came in "under budget", and the
         * five points for price were full marks for everybody.
         */
        public ?int $budgetMonthlyPiastres = null,
        /**
         * How much earlier or later than the stated window is still acceptable. Screen 18
         * shows it as "Flexibility ± 15 min".
         */
        public int $flexibilityMinutes = 15,
        /** Screen 18's "Add a return ride (~5:00 PM)". */
        public bool $wantsReturnTrip = false,
        public array $requiredRules = [],
    ) {}

    /**
     * What one seat may cost, derived from the monthly ceiling.
     *
     * The scoring compares against a single trip's price, so the monthly figure has to be
     * divided by how many rides a month this pattern actually is: the committed days,
     * times the average weeks in a month, doubled when a return leg was asked for.
     *
     * 52/12 rather than 4, because 4 undercounts by nearly a week a month — which would
     * quietly hand every commute a worse price score than it deserves.
     */
    public function perSeatCeilingPiastres(): ?int
    {
        if ($this->budgetMonthlyPiastres === null || $this->budgetMonthlyPiastres <= 0) {
            return null;
        }

        $daysPerWeek = substr_count(decbin($this->days->value), '1');

        if ($daysPerWeek === 0) {
            return null;
        }

        $ridesPerMonth = $daysPerWeek * (52 / 12) * ($this->wantsReturnTrip ? 2 : 1);

        return (int) floor($this->budgetMonthlyPiastres / $ridesPerMonth);
    }

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
            budgetMonthlyPiastres: isset($input['budgetMonthlyPiastres'])
                ? (int) $input['budgetMonthlyPiastres']
                : null,
            flexibilityMinutes: (int) ($input['flexibilityMinutes'] ?? 15),
            wantsReturnTrip: (bool) ($input['wantsReturnTrip'] ?? false),
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
            $this->budgetMonthlyPiastres ?? 'none',
            $this->flexibilityMinutes,
            $this->wantsReturnTrip ? 'return' : 'one-way',
            implode(',', $this->requiredRules),
        ]));
    }
}
