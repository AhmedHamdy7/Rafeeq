<?php

return [

    /*
     * Shown to the driver while they build a commute. Each one says what to
     * change, not which check failed.
     */
    'route' => [
        'origin_equals_destination' => 'The starting point and the destination need to be different places.',
        'too_many_stops' => 'That is more pickup or drop-off points than a commute can have.',
        'pickup_off_route' => 'That pickup point is more than :minutes minutes out of your way. Move it closer to your route, or raise the detour you are willing to make.',
    ],

    'schedule' => [
        'start_in_past' => 'The start date cannot be in the past.',
        'no_days_selected' => 'Choose at least one day of the week.',
        'too_far_ahead' => 'A commute can run for at most :months months.',
    ],

    'seats' => [
        'exceeds_vehicle' => 'Your vehicle seats :seats people including you, so you cannot offer more than :bookable.',
    ],

    /*
     * Recorded as the reason on a booking the SYSTEM cancelled on a passenger's
     * behalf, so the audit trail says why rather than just who.
     */
    'absence' => [
        'planned' => 'Planned absence',
    ],

    'group' => [
        'left' => 'Left the group',
    ],

];
