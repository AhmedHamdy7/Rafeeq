<?php

/*
 * The dashboard shell: sidebar, top bar, account menu. Page texts live in
 * lang/en/admin/{section}.php, one file per section, mirrored in lang/ar.
 */
return [
    'map_failed' => 'The map could not load. Everything else on this page is still correct.',
    'map_legend' => ['route' => 'Planned route', 'trail' => 'GPS trail', 'pickup' => 'Pickups', 'dropoff' => 'Drop-off', 'car' => 'Car now'],
    'brand' => 'YuGo',
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
        'app_versions' => 'App versions',
        'system' => 'System',
        'team' => 'Team and roles',
        'audit' => 'Audit log',
    ],

    'search' => [
        'label' => 'Search users',
        'placeholder' => 'Search phone, name, plate, case or booking reference',
        'groups' => [
            'members' => 'Members',
            'vehicles' => 'Cars',
            'references' => 'References',
            'pages' => 'Pages',
        ],
        'kinds' => [
            'booking' => 'Booking',
            'report' => 'Report',
            'sos' => 'SOS',
            'support' => 'Support case',
            'commute' => 'Commute',
            'trip_day' => 'Trip day',
        ],
        'none' => 'Nothing matches. Try a full phone number, a plate or a reference code.',
        'all_members' => 'Search Users for “:q”',
        'keys' => '↑ ↓ to move · Enter to open · Esc to close',
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
    'alerts' => [
        'title' => 'Needs attention',
        'none' => 'Nothing is waiting past its time.',
        'label' => '{0} No alerts|{1} :count alert|[2,*] :count alerts',
        'sos_untouched' => '{1} SOS nobody has picked up|[2,*] SOS alerts nobody has picked up',
        'reports_overdue' => '{1} report past its deadline|[2,*] reports past their deadline',
        'payments_late' => '{1} payment waiting over :hours h|[2,*] payments waiting over :hours h',
        'drivers_late' => '{1} driver application waiting over :hours h|[2,*] driver applications waiting over :hours h',
        'escalations' => '{1} escalation from CS|[2,*] escalations from CS',
    ],
];
