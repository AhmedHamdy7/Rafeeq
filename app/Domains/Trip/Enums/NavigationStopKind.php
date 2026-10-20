<?php

namespace App\Domains\Trip\Enums;

/**
 * What one stop on a day's navigation itinerary is (Feature 03 v5 §5:
 * `DRIVER_START|PASSENGER_PICKUP|PASSENGER_DROPOFF|DRIVER_END`).
 *
 * 🔴 The driver's two endpoints are their own kinds, and are NOT passenger stops. v5 §1 is explicit
 * that the start and end are *"the driver's journey endpoints, **NOT** automatically passenger
 * pickup/drop-off stops"* — so a passenger who happens to be collected at the driver's start point
 * still has her own `PassengerPickup` row there. Collapsing the two would make the itinerary say
 * the driver's own front door was somebody's agreed meeting place, which is a privacy claim as much
 * as a routing one.
 *
 * With N confirmed passengers an itinerary holds **2 + 2N** stops: one start, one end, and a pickup
 * and a drop-off for each rider.
 */
enum NavigationStopKind: string
{
    /** Where the driver's own journey begins. Always first, exactly one per itinerary. */
    case DriverStart = 'driver_start';

    case PassengerPickup = 'passenger_pickup';

    case PassengerDropoff = 'passenger_dropoff';

    /** Where the driver's own journey ends. Always last, exactly one per itinerary. */
    case DriverEnd = 'driver_end';

    /** Whether this stop belongs to a passenger's booking rather than to the driver's route. */
    public function belongsToPassenger(): bool
    {
        return $this === self::PassengerPickup || $this === self::PassengerDropoff;
    }

    /**
     * Whether reaching this stop means somebody gets in.
     *
     * Read by the in-progress navigation, which shows remaining actionable stops: a pickup still to
     * come is somebody standing on a kerb waiting, and a drop-off still to come is somebody in the
     * car. They are not the same urgency.
     */
    public function isCollection(): bool
    {
        return $this === self::PassengerPickup;
    }
}
