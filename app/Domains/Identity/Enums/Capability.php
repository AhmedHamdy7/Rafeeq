<?php

namespace App\Domains\Identity\Enums;

/**
 * What an account is enrolled to do (Feature 01 v3 §1: "One account can have Passenger and Driver
 * capabilities; mode is a UI context, not another login").
 *
 * There is no `Both` case here on purpose, and that is the whole difference between this and
 * {@see RegisteredRole}. "Both" is not a third kind of capability — it is two rows. Giving it a
 * case would mean every reader asking "may she drive?" had to remember to check two values instead
 * of one, and the first reader to forget would deny a driver her own screen.
 *
 * This enum is also reused for `user_preferences.last_active_mode`, because the active mode is
 * simply which capability the person is currently using. A separate `ActiveMode` enum with the
 * same two cases would let the two drift apart and mean nothing by it.
 */
enum Capability: string
{
    case Passenger = 'passenger';
    case Driver = 'driver';

    /**
     * The capabilities a sign-up intent grants.
     *
     * This is the one place the old single-column world is translated into the new row-per-
     * capability one, so that `registered_role` can stay immutable history while this stays the
     * live authority.
     *
     * @return array<int, self>
     */
    public static function forRegisteredRole(RegisteredRole $role): array
    {
        return match ($role) {
            RegisteredRole::Passenger => [self::Passenger],
            RegisteredRole::Driver => [self::Driver],
            RegisteredRole::Both => [self::Passenger, self::Driver],
        };
    }

    /**
     * Which home screen to open for somebody arriving with these capabilities.
     *
     * Feature 01 v3 §4 is explicit that **Both starts Passenger**, so this deliberately does not
     * prefer the driver side even though it is the richer screen.
     *
     * @param  array<int, self>  $capabilities
     */
    public static function defaultModeFor(array $capabilities): ?self
    {
        if (in_array(self::Passenger, $capabilities, true)) {
            return self::Passenger;
        }

        return in_array(self::Driver, $capabilities, true) ? self::Driver : null;
    }
}
