<?php

namespace App\Domains\Trip\Enums;

/**
 * Where a run has got to, as a state machine (Chapter 8's "Trip States").
 *
 * 🔴 Written as transitions rather than as a set of labels because this sequence is
 * what the money and the disputes are read off. A run that could go straight from
 * `preparing` to `completed` is a run where nobody was ever collected and everybody
 * was charged, and the support ticket that follows has no record of which step was
 * skipped.
 *
 * `at_pickup` is in the middle of the driving states on purpose: it is the moment the
 * wait timer runs, and it can be entered more than once on a run with several
 * pickups — so it goes back to `en_route` as well as forward.
 */
enum TripSessionStatus: string
{
    case Preparing = 'preparing';
    case EnRoute = 'en_route';
    case AtPickup = 'at_pickup';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Emergency = 'emergency';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the run is out on the road right now.
     *
     * What GPS points, wait timers and check-ins are all gated on: none of them mean
     * anything on a run that has not left or has already finished.
     */
    public function isUnderway(): bool
    {
        return $this === self::EnRoute
            || $this === self::AtPickup
            || $this === self::InProgress;
    }

    /**
     * The same three, as column values, for the queries that have to ask it in SQL.
     *
     * 🔴 Derived from `isUnderway()` rather than listed again. A sweep that hard-coded the three
     * strings would be a second definition of "under way", and the day a fourth status joined them
     * the sweep would quietly stop seeing those runs — which for the sweep that closes unreachable
     * offers means leaving somebody holding an offer for a car that has gone.
     *
     * @return list<string>
     */
    public static function underwayValues(): array
    {
        $values = [];

        foreach (self::cases() as $case) {
            if ($case->isUnderway()) {
                $values[] = $case->value;
            }
        }

        return $values;
    }

    /**
     * @return list<self>
     */
    private function allowedTransitions(): array
    {
        return match ($this) {
            /*
             * `Emergency` is reachable from every live state and from nowhere else.
             * It is not a step in the sequence — it is the sequence being abandoned,
             * and a run in it stays in it until somebody who is not the driver decides
             * what happened (Phase 11).
             */
            self::Preparing => [self::EnRoute, self::Cancelled, self::Emergency],
            self::EnRoute => [self::AtPickup, self::InProgress, self::Cancelled, self::Emergency],
            // Back to `en_route` for the next pickup, or forward once everybody aboard.
            self::AtPickup => [self::EnRoute, self::InProgress, self::Cancelled, self::Emergency],
            /*
             * `Cancelled` IS reachable from here — v5 §125's Cancel Active Trip — but only through
             * {@see \App\Domains\Trip\Actions\CancelActiveTripAction}, and the restriction is not a
             * convention. This used to read "no cancelled from here", because cancelling with
             * people in the car would leave them mid-journey with no record of having travelled.
             * That objection was right about the danger and wrong about the cure: refusing the
             * transition did not stop a driver abandoning a journey, it only stopped the system
             * recording it, and v5 asks for the record — a fee, a reliability event, an appeal.
             *
             * What answers the objection is that the Action resolves every passenger FIRST:
             * whoever was aboard keeps her attendance and ends up `ended_early`, so the journey she
             * made is still on her booking. {@see \App\Domains\Trip\Actions\AdvanceTripAction}
             * refuses `Cancelled` outright so no bare transition can skip that.
             */
            self::InProgress => [self::Completed, self::Cancelled, self::Emergency],
            default => [],
        };
    }
}
