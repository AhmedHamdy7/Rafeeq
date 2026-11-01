<?php

namespace App\Domains\Commute\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * The limits every commute's route lives inside (COM-31, COM-16, decision C8 of 2026-10-31).
 *
 * Same contract as the other settings readers: `platform_settings` wins and `config/rafeeq.php`
 * holds the shipped default, because every one of these is a policy figure that has to move without
 * a deploy (standard #11, ADM-06).
 *
 * 🔴 **None of these belongs to the driver any more.** They used to be per commute
 * (`max_detour_minutes`, `max_walk_minutes`, chosen by the driver from 0 to 30). The partners took
 * them away on 2026-10-31 for one reason: the driver's tolerance is paid for by the passenger who
 * was confirmed first. Every extra pickup makes her morning longer, and a driver who chose thirty
 * minutes was choosing to make her late for work.
 */
final class RouteSettings
{
    /** How far a passenger's pickup or drop-off may sit from the driver's route, in metres. */
    public static function matchRadiusMeters(): int
    {
        return max(1, (int) self::get('commute.match_radius_meters'));
    }

    /**
     * The most extra time the WHOLE trip may carry for its riders, in minutes.
     *
     * Read together with {@see self::maxTripDetourMeters()}: "10 minutes or 5 km, whichever is
     * less" means both have to hold.
     */
    public static function maxTripDetourMinutes(): int
    {
        return max(0, (int) self::get('commute.max_trip_detour_minutes'));
    }

    /** The most extra distance the whole trip may carry for its riders, in metres. */
    public static function maxTripDetourMeters(): int
    {
        return max(0, (int) self::get('commute.max_trip_detour_meters'));
    }

    /** The most distinct pickup stops one commute takes — suggested by the driver, or on one day. */
    public static function maxPickupStops(): int
    {
        return max(1, (int) self::get('commute.max_pickup_points'));
    }

    /** The most distinct drop-off stops one commute takes — suggested by the driver, or on one day. */
    public static function maxDropoffStops(): int
    {
        return max(1, (int) self::get('commute.max_dropoff_points'));
    }

    /**
     * How many hours before departure a day's arrangements freeze (COM-16).
     *
     * 🔴 Also the moment the computed departure stops moving (C-2): before it, a new rider may make
     * the driver leave earlier; after it, the departure is a promise and a new rider has to fit it.
     */
    public static function scheduleLockHours(): int
    {
        return max(1, (int) self::get('commute.schedule_lock_hours'));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
