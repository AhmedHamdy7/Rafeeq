<?php

namespace App\Http\Resources;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Enums\GroupAttendanceStatus;
use App\Domains\Group\Models\CommuteGroup;
use Illuminate\Support\Carbon;

/**
 * The parts of a journey that both home screens show, in one place.
 *
 * Screen 9 (passenger) and screen 23 (driver) are different screens with the same
 * middle: where the journey goes, when it leaves, and how many of the group have
 * said they are coming. Written once here so the two cannot drift into giving
 * different answers about the same trip.
 */
final class JourneyCard
{
    /**
     * Where the journey starts and ends, as the addresses the driver entered.
     *
     * The intermediate `pickup` stops are NOT included, and that is the point:
     * they are other passengers' meeting points, and a list of them is a list of
     * where several people stand every morning.
     *
     * @return array{originLabel: string|null, destinationLabel: string|null}
     */
    public static function route(CommuteOffer $offer): array
    {
        $origin = $offer->locations->firstWhere('type', CommuteLocationType::Origin);
        $destination = $offer->locations->firstWhere('type', CommuteLocationType::Destination);

        return [
            'originLabel' => $origin?->address,
            'destinationLabel' => $destination?->address,
        ];
    }

    /**
     * When it leaves, three ways.
     *
     * 🔴 `departsInMinutes` is the SERVER's countdown at the moment of the response, and
     * both screens lead with it ("departs in 51 min"). It is here because a phone with a
     * skewed clock would otherwise count down to the wrong moment, and a driver who
     * believes they have 51 minutes when they have 5 misses the run — which on a commute
     * platform is somebody late for work, not an inconvenience.
     *
     * It goes stale the second it is sent, so `departureAt` is the thing to count down
     * from; this is the offset to correct the phone's clock against.
     *
     * Negative once the departure time has passed, rather than clamped to zero: a trip
     * that is running late is a fact both screens need to be able to show.
     *
     * @return array{departureAt: string, departureLocal: string, departsInMinutes: int, isToday: bool}
     */
    public static function timing(ScheduledTrip $trip): array
    {
        return [
            'departureAt' => $trip->departure_at->toIso8601String(),
            'departureLocal' => $trip->departure_local->format('Y-m-d H:i:s'),
            'departsInMinutes' => (int) round(now()->diffInMinutes($trip->departure_at, absolute: false)),

            /*
             * Derived from the LOCAL clock of the journey, not the server's: "driving
             * today" is a statement about the day where the commute happens. Computing
             * it from a UTC date would call a 01:00 Cairo departure yesterday's run.
             */
            'isToday' => $trip->departure_local->isSameDay(
                Carbon::now($trip->commuteSchedule->timezone),
            ),
        ];
    }

    /**
     * "Attendance · 2 of 3 in".
     *
     * 🔴 Returned as four counts rather than the screen's one fraction, because the
     * fraction hides the difference that matters: somebody who said they are away and
     * somebody who has not answered yet are not the same person to a driver deciding
     * whether to wait. Pre-baking "2 of 3" would have the server pick which of the two
     * the missing seat is, and it does not know.
     *
     * `total` counts ACTIVE members including the driver, which is the reading that makes
     * the prototype's own numbers add up: a three-seat group with two passengers shows
     * "2 of 3" when the driver and one passenger have said yes.
     *
     * The declared intention, never the actual check-in — those are different tables on
     * purpose, and only the check-in decides money (see GroupAttendance).
     *
     * @return array{coming: int, away: int, awaiting: int, total: int}
     */
    public static function attendance(?CommuteGroup $group, ScheduledTrip $trip): array
    {
        if ($group === null) {
            return ['coming' => 0, 'away' => 0, 'awaiting' => 0, 'total' => 0];
        }

        $total = $group->activeMembers->count();

        $declared = $group->attendances->where('scheduled_trip_id', $trip->id);

        $coming = $declared->where('status', GroupAttendanceStatus::Coming)->count();
        $away = $declared->where('status', GroupAttendanceStatus::Away)->count();

        return [
            'coming' => $coming,
            'away' => $away,
            // Everyone who has not answered, whether by holding a `no_response` row or
            // by having no row at all — the client should not have to know which.
            'awaiting' => max(0, $total - $coming - $away),
            'total' => $total,
        ];
    }
}
