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
         * How much earlier or later than the stated window is still acceptable. Screen 18
         * shows it as "Flexibility ± 15 min".
         */
        public int $flexibilityMinutes = 15,
        /** Screen 18's "Add a return ride (~5:00 PM)". */
        public bool $wantsReturnTrip = false,
        public array $requiredRules = [],
        /**
         * 🔴 The prototype's `min rating` filter — a HARD one, per the Bible's own rule that
         * "hard conflicts never receive a soft score". A passenger who says she will not ride
         * with anyone under four stars is stating a condition, not a preference, and scoring it
         * would put a 3.1-star driver in her results with a lower rank.
         *
         * 🔒 A driver with NO rating is NOT excluded, and that is the decision worth stating:
         * `null` means "not rated yet", never zero. Excluding unrated drivers would freeze every
         * new driver out of every filtered search — which is both a cold-start problem that
         * starves supply and a wrong answer, since nobody has said anything bad about her. See
         * HardFilters::requireMinimumRating.
         */
        public ?float $minRating = null,
        /**
         * 🔴 The Women-only FILTER (Feature 03 C-11, BOOK-02): only women-only commutes come back.
         *
         * A filter she chose, not eligibility. Women-only commutes are shown to everybody now —
         * who may ASK for a seat on one is decided at request time, from the account
         * ({@see HardFilters::mayJoinAudience()}).
         */
        public bool $womenOnly = false,
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
            flexibilityMinutes: (int) ($input['flexibilityMinutes'] ?? 15),
            wantsReturnTrip: (bool) ($input['wantsReturnTrip'] ?? false),
            requiredRules: $input['rules'] ?? [],
            minRating: isset($input['minRating']) ? (float) $input['minRating'] : null,
            womenOnly: filter_var($input['womenOnly'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * The same search, in the shape {@see fromArray} accepts.
     *
     * 🔴 Exists so a recorded search can be handed back to a client and re-run by sending it
     * straight back (Feature 03 v5's Recent Searches). Written from the object rather than by
     * storing the request's own input, because a search arrives as a QUERY STRING: every value in
     * it is a string, so `daysMask` would come back as `"62"` and a client doing arithmetic on it
     * would be surprised by its own data. Round-tripping through here makes the stored criteria
     * independent of the transport that carried them.
     *
     * Optional fields are omitted rather than sent as null, so what comes back looks like what a
     * person actually filled in.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'origin' => ['lat' => $this->origin->lat, 'lng' => $this->origin->lng],
            'destination' => ['lat' => $this->destination->lat, 'lng' => $this->destination->lng],
            'daysMask' => $this->days->value,
            'arrivalWindowStart' => $this->arrivalWindowStart,
            'arrivalWindowEnd' => $this->arrivalWindowEnd,
            'maxWalkMinutes' => $this->maxWalk->minutes,
            'maxDetourMinutes' => $this->maxDetourMinutes,
            'seatsNeeded' => $this->seatsNeeded,
            'audiencePreference' => $this->audiencePreference?->value,
            'flexibilityMinutes' => $this->flexibilityMinutes,
            'wantsReturnTrip' => $this->wantsReturnTrip,
            'rules' => $this->requiredRules,
            'minRating' => $this->minRating,
            // Omitted when off, like every other optional field.
            'womenOnly' => $this->womenOnly ?: null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
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
            $this->flexibilityMinutes,
            $this->wantsReturnTrip ? 'return' : 'one-way',
            implode(',', $this->requiredRules),
            // Part of the key: two passengers with identical criteria and different minimums are
            // asking different questions, and sharing a cache row would answer one of them wrong.
            $this->minRating ?? 'any',
            $this->womenOnly ? 'women-only' : 'any-audience',
        ]));
    }
}
