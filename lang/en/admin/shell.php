<?php

/*
 * The dashboard shell: sidebar, top bar, account menu. Page texts live in
 * lang/en/admin/{section}.php, one file per section, mirrored in lang/ar.
 */
return [
    'brand' => 'RAFEEQ',
    'brand_sub' => 'Operations',

    'groups' => [
        'overview' => 'Overview',
        'live' => 'Live',
        'queues' => 'Queues',
        'directory' => 'Directory',
        'money' => 'Money',
        'communication' => 'Communication',
        'configuration' => 'Configuration',
        'team' => 'Team',
    ],

    'nav' => [
        'label' => 'Main menu',
        'open' => 'Open menu',
        'collapse' => 'Shrink or expand the menu',

        'home' => 'Home',
        'statistics' => 'Statistics',
        'sos' => 'SOS alerts',
        'live_trips' => 'Live trips',
        'escort' => 'Night escort',
        'driver_verification' => 'Driver verification',
        'identity' => 'Identity checks',
        'replacement_cars' => 'Replacement cars',
        'reports' => 'Reports and disputes',
        'appeals' => 'Appeals',
        'payments' => 'Payments to confirm',
        'coupons' => 'Coupons to issue',
        'users' => 'Users',
        'vehicles' => 'Vehicles',
        'commutes' => 'Commutes',
        'trip_days' => 'Trip days',
        'bookings' => 'Bookings and requests',
        'demands' => 'Demands',
        'settlements' => 'Weekly settlements',
        'ledger' => 'Ledger',
        'announcements' => 'Announcements',
        'support' => 'Support cases',
        'settings' => 'Settings',
        'content' => 'Content',
        'team' => 'Team and roles',
        'audit' => 'Audit log',
    ],

    'search' => [
        'label' => 'Search users',
        'placeholder' => 'Search phone, name, plate, case or booking reference',
    ],

    'sos' => [
        'indicator' => 'SOS',
    ],

    'language' => 'Language',
    'theme' => 'Switch light or dark',
    'signed_in_as' => 'Signed in as',
    'sign_out' => 'Sign out',

    // The two roles the design draws, and the finer roles we keep underneath them.
    'roles' => [
        'super_admin' => 'Admin',
        'customer_service' => 'CS',
        'operations' => 'Operations',
        'verification' => 'Verification',
        'finance' => 'Finance',
        'support' => 'Support',
        'safety_lead' => 'Safety lead',
    ],

    'states' => [
        'empty_title' => 'Nothing here yet',
        'error_title' => 'This did not load',
        'error_text' => 'Try again. If it keeps happening, tell the team lead.',
        'retry' => 'Try again',
        'locked_title' => 'Not available to your role',
        'locked_text' => 'Ask an Admin if you need this.',
        'loading' => 'Loading',
    ],
];
