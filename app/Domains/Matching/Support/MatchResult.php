<?php

namespace App\Domains\Matching\Support;

use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;

/**
 * One result card: a commute, the day it was matched on, why it scored what it
 * did, and where the passenger would meet it — and leave it.
 */
final readonly class MatchResult
{
    /**
     * @param  array<int, ScheduledTrip>  $otherDays  the remaining matching days of the
     *                                                same commute, so a recurring search shows one card instead of
     *                                                twenty near-identical ones
     * @param  CommuteLocation|null  $dropOffPoint  the commute's stop nearest where she is going
     *                                              (DISCOVER-2): a drop-off point or its destination
     * @param  int|null  $meetingPointSeconds  how far into the run the meeting point sits, at the
     *                                         published route's pace (DISCOVER-14); null without a route
     * @param  int|null  $dropOffSeconds  the same for the drop-off point
     */
    public function __construct(
        public CommuteOffer $offer,
        public ScheduledTrip $trip,
        public ScoreBreakdown $score,
        public CommuteLocation $meetingPoint,
        public array $otherDays = [],
        public ?CommuteLocation $dropOffPoint = null,
        public ?float $dropOffWalkMinutes = null,
        public ?int $meetingPointSeconds = null,
        public ?int $dropOffSeconds = null,
    ) {}

    public function withOtherDays(array $otherDays): self
    {
        return new self(
            $this->offer,
            $this->trip,
            $this->score,
            $this->meetingPoint,
            $otherDays,
            $this->dropOffPoint,
            $this->dropOffWalkMinutes,
            $this->meetingPointSeconds,
            $this->dropOffSeconds,
        );
    }
}
