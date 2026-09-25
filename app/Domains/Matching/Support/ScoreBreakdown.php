<?php

namespace App\Domains\Matching\Support;

/**
 * A match's score, component by component.
 *
 * Stored and returned broken down rather than as one number for two reasons the
 * Bible states outright: the match-details screen shows a passenger WHY a
 * commute scored what it did, and when the formula is tuned the components let
 * before and after be compared. A single total would make both impossible.
 */
final readonly class ScoreBreakdown
{
    public function __construct(
        public int $overlap,
        public int $schedule,
        public int $detour,
        public int $audience,
        public int $comfort,
        public int $price,
        public int $reliability,
        public float $walkMinutes,
        public float $detourMinutes,
    ) {}

    public function total(): int
    {
        return $this->overlap + $this->schedule + $this->detour
            + $this->audience + $this->comfort + $this->price + $this->reliability;
    }

    /**
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        return [
            'total' => $this->total(),
            'overlap_score' => $this->overlap,
            'schedule_score' => $this->schedule,
            'detour_score' => $this->detour,
            'audience_score' => $this->audience,
            'comfort_score' => $this->comfort,
            'price_score' => $this->price,
            'reliability_score' => $this->reliability,
            'walk_minutes' => round($this->walkMinutes, 1),
            'detour_minutes' => round($this->detourMinutes, 1),
        ];
    }
}
