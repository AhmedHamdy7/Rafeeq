<?php

return [

    /*
     * Shown to the driver while they build a commute. Each one says what to
     * change, not which check failed.
     */
    'route' => [
        'origin_equals_destination' => 'The starting point and the destination need to be different places.',
        'too_many_stops' => 'That is more pickup or drop-off points than a commute can have.',
        'pickup_off_route' => 'That pickup point is more than :minutes minutes out of your way. Move it closer to your route.',
        'dropoff_off_route' => 'That drop-off point is more than :minutes minutes out of your way. Move it closer to your route.',
        'pickup_too_far' => 'That pickup point is more than :km km from your route. Move it closer to your route.',
        'dropoff_too_far' => 'That drop-off point is more than :km km from your route. Move it closer to your route.',
    ],

    'schedule' => [
        'start_in_past' => 'The start date cannot be in the past.',
        'no_days_selected' => 'Choose at least one day of the week.',
        'too_far_ahead' => 'A commute can run for at most :months months.',
        'return_timed_by_leave' => 'A return commute is timed by when you leave..',
        'going_timed_by_arrival' => 'A going commute is timed by when you arrive..',
    ],

    // P-1: the price is automatic, so a request that sends one is refused.
    'price' => [
        'set_by_rafeeq' => 'YuGo sets the seat price from your route and your car. It cannot be sent or changed.',
    ],

    'seats' => [
        'exceeds_approved' => 'You can offer at most :approved seats: that is how many seats with working seat belts were approved on your car.',
    ],

    /*
     * Recorded as the reason on a booking the SYSTEM cancelled on a passenger's
     * behalf, so the audit trail says why rather than just who.
     */
    'absence' => [
        'planned' => 'Planned absence',
    ],

    'alternatives' => [
        'current_location' => 'Where the car stopped',
        'your_dropoff' => 'Your drop-off',
    ],

    'group' => [
        'left' => 'Left the group',
    ],

];
