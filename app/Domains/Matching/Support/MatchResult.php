<?php

namespace App\Domains\Matching\Support;

use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;

/**
 * One result card: a commute, the day it was matched on, why it scored what it
 * did, and where the passenger would meet it.
 */
final readonly class MatchResult
{
    /**
     * @param  array<int, ScheduledTrip>  $otherDays  the remaining matching days of the
     *                                                same commute, so a recurring search shows one card instead of
     *                                                twenty near-identical ones
     */
    public function __construct(
        public CommuteOffer $offer,
        public ScheduledTrip $trip,
        public ScoreBreakdown $score,
        public CommuteLocation $meetingPoint,
        public array $otherDays = [],
    ) {}

    public function withOtherDays(array $otherDays): self
    {
        return new self($this->offer, $this->trip, $this->score, $this->meetingPoint, $otherDays);
    }
}
