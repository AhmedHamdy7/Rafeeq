<?php

namespace App\Domains\Matching\Support;

use App\Domains\Shared\ValueObjects\Coordinate;

/**
 * Where a list of alternatives was searched from, and on what authority.
 *
 * 🔴 The reason travels with the coordinate because the client cannot infer it and the screen is
 * different in each case. "Other commutes from here" belongs over a list searched from a position
 * the car actually reported; "other commutes from your usual stop" belongs over one searched from
 * her agreed pickup. Showing the first caption over the second would tell a woman standing on the
 * Mehwar that these cars pass where she is, when they pass where she got in.
 *
 * It is also how the uncertain case stays honest. {@see self::AGREED_PICKUP_UNVERIFIED} means she
 * WAS carried and nothing recorded how far — so the list is useful but its starting point is a
 * guess, and a client that knows this can offer her the map instead of implying we know.
 */
final readonly class AlternativeOrigin
{
    /** She was never collected, so this is where she still is. */
    public const string AGREED_PICKUP = 'agreed_pickup';

    /** She was in the car, and the car reported where it was. v5's "current termination location". */
    public const string WHERE_THE_CAR_STOPPED = 'where_the_car_stopped';

    /** She was in the car; its last position had expired, so this is the furthest stop it reached. */
    public const string LAST_STOP_REACHED = 'last_stop_reached';

    /** She was in the car and nothing recorded where it got to. Her pickup, as the last resort. */
    public const string AGREED_PICKUP_UNVERIFIED = 'agreed_pickup_unverified';

    public function __construct(
        public Coordinate $point,
        /** One of the four constants above. */
        public string $kind,
        public ?string $label = null,
    ) {}
}
