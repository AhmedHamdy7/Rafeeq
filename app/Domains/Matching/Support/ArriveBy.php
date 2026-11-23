<?php

namespace App\Domains\Matching\Support;

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\RouteSettings;
use Carbon\CarbonImmutable;

/**
 * Review items 12 and 16: the demand's and the search's time is "arrive by". A commute fits when
 * its promised arrival that day is between the time minus 30 minutes and the time. A window
 * (`arrivalWindowStart` … `arrivalWindowEnd`) widens it: start minus 30 minutes … end.
 */
final class ArriveBy
{
    public const int WINDOW_MINUTES = 30;

    public static function fits(ScheduledTrip $trip, SearchCriteria $criteria): bool
    {
        if ($criteria->isReturn()) {
            return self::leavesInWindow($trip, $criteria->leaveAt);
        }

        if (! $criteria->arriveBy) {
            return true;
        }

        $arrival = self::arrivalLocal($trip);

        return $arrival >= self::earliest($criteria) && $arrival <= $criteria->arrivalWindowEnd;
    }

    /**
     * B4, D1: a Return fits "Leave at T" when its promised leave time that day is in
     * [T, T + `returnWindowMinutes`]. The leave time is the driver's at the start of his route.
     */
    public static function leavesInWindow(ScheduledTrip $trip, ?string $leaveAt): bool
    {
        if ($leaveAt === null) {
            return true;
        }

        $leave = self::leaveLocal($trip);

        return $leave >= $leaveAt && $leave <= self::latestLeave($leaveAt);
    }

    public static function latestLeave(string $leaveAt): string
    {
        $seconds = strtotime("1970-01-01 {$leaveAt} UTC") + RouteSettings::returnWindowMinutes() * 60;

        return gmdate('H:i:s', min(86_399, $seconds));
    }

    /** The day's leave time on the commute's own clock (`HH:MM:SS`). */
    public static function leaveLocal(ScheduledTrip $trip): string
    {
        return CarbonImmutable::instance($trip->departure_local)->format('H:i:s');
    }

    public static function earliest(SearchCriteria $criteria): string
    {
        $seconds = strtotime("1970-01-01 {$criteria->arrivalWindowStart} UTC") - self::WINDOW_MINUTES * 60;

        return gmdate('H:i:s', max(0, $seconds));
    }

    /**
     * The promised arrival on the commute's own clock (`HH:MM:SS`). A day with no promised arrival
     * uses its departure plus the route's duration.
     */
    public static function arrivalLocal(ScheduledTrip $trip): string
    {
        $departure = CarbonImmutable::instance($trip->departure_local);

        if ($trip->promised_arrival_at !== null && $trip->departure_at !== null) {
            return $departure->addSeconds((int) $trip->departure_at->diffInSeconds($trip->promised_arrival_at, false))->format('H:i:s');
        }

        $duration = $trip->relationLoaded('commuteOffer') ? (int) $trip->commuteOffer?->route_duration_seconds : 0;

        return $departure->addSeconds($duration)->format('H:i:s');
    }
}
