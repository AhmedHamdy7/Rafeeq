<?php

return [

    'title' => 'Rafeeq Operations',

    'nav' => [
        'verifications' => 'Verification',
        'drivers' => 'Drivers',
        'safety' => 'Safety cases',
        'trips' => 'Live trips',
        'operations' => 'Operations',
        'signed_in_as' => 'Signed in as',
        'menu' => 'Open menu',
        'close' => 'Close menu',
        'theme' => 'Switch light or dark',
        'sign_out' => 'Sign out',
    ],

    'login' => [
        'subtitle' => 'Staff access. Both steps are required.',
        'email' => 'Work email',
        'password' => 'Password',
        'continue' => 'Continue',
        'code' => '6-digit code',
        /*
         * Spells out the distinction because the first person to use this page typed the
         * SECRET into the code box. The secret is a key installed in the app once; the
         * code is what the app then displays and rotates. Both are strings of capital
         * letters and digits from the reader's point of view, so the difference has to be
         * said rather than implied.
         */
        'code_hint' => 'Open your authenticator app and enter the 6-digit code it is showing — not the setup key you added to it.',
        'verify' => 'Verify and sign in',
    ],

    'session' => [
        'expired' => 'You were signed out because the session was idle.',
        'mfa_required' => 'Sign in again and confirm your authenticator code.',
    ],

    /*
     * The review queue's check lines. Each one is a fact about the submission, shown
     * to a reviewer who is deciding — never a verdict about the person.
     */
    'queue' => [
        'title' => 'Identity verification',
        'waiting' => '{0} Nothing waiting for review.|{1} :count person waiting.|[2,*] :count people waiting.',
        'empty' => 'The queue is empty.',
        'uploaded' => 'Uploaded',
        'scan_pending' => 'Scan pending',
        'scan_infected' => 'Failed virus scan',
        'scan_failed' => 'Scan could not complete',
        'documents' => 'Documents',
        'none_attached' => 'None attached',
        'attempts' => 'Trips through review',
        'licence' => 'Licence expiry',
        'risk_ok' => 'Standard',
        'risk_warn' => 'Review',
        'risk_bad' => 'Needs care',
        'working' => 'Working…',
        'cancel' => 'Cancel',
        'approve' => 'Approve',
        'ask_info' => 'Ask for more',
        'reason' => 'message to the member',
        // 🔒 The reviewer's text is shown to the person verbatim, so the prompt asks
        // for something they can act on rather than a note to the file.
        'reason_placeholder' => 'What should they do differently? They read this exactly as you write it.',
        'send_request' => 'Send request',
        'approved' => 'Approved :name.',
        'info_requested' => 'Asked :name for more.',
        'view_only' => 'You can see this queue but not decide on it.',
    ],

    'drivers' => [
        'title' => 'Driver applications',
        'waiting' => '{0} No applications waiting.|{1} :count application waiting.|[2,*] :count applications waiting.',
        'empty' => 'No applications waiting.',
        'licence_expired' => 'Licence expired',
        'licence_until' => 'Licence valid to :date',
        'seats' => '{1} :count seat|[2,*] :count seats',
        'approve' => 'Approve driver',
        'reject' => 'Reject',
        'confirm_reject' => 'Confirm rejection',
        'approved' => 'Approved :name as a driver.',
        'rejected' => 'Rejected :name.',
    ],

    'home' => [
        'nothing_yet' => 'Your role has no dashboard page yet.',
    ],

    /*
     * The safety desk. Two audiences in one file and they must not be mixed up: most lines
     * are read by staff, but `hint_resolve` / `hint_close` say plainly that the text typed
     * under them goes to the REPORTER, because it does (IncidentResource::resolution).
     */
    'safety' => [
        'title' => 'Safety cases',
        'alerts_live' => '{0} No live alerts|{1} :count live alert|[2,*] :count live alerts',
        'reports_open' => '{0} no open reports|{1} :count open report|[2,*] :count open reports',
        'alerts' => 'Live alerts',
        'reports' => 'Open reports',
        'raised' => 'Raised :ago, at :time',
        'silent' => 'Silent alert',
        'silent_hint' => 'Silent alert: the member may not be able to speak. Do not phone them first.',
        'nobody_on_it' => 'Nobody on it',
        'picked_up_by' => 'With :name · answered in :seconds s',
        'where' => 'Where it was raised',
        'no_location' => 'No location sent',
        'trip' => 'Trip',
        'no_trip' => 'Not on a trip',
        'driver_and_car' => 'Driver and car',
        'acknowledge' => 'I am on it',
        'record_outcome' => 'Record how it ended',
        'outcome' => 'outcome',
        'outcomes' => [
            'false_alarm' => 'False alarm',
            'resolved' => 'Resolved — member safe',
            'escalated_police' => 'Handed to the police',
        ],
        'note' => 'note',
        'note_placeholder' => 'What happened and what you did. This is the record of this emergency.',
        'close_alert' => 'Close alert',
        'no_alerts' => 'No live alerts.',
        'categories' => [
            'harassment' => 'Harassment',
            'unsafe_driving' => 'Unsafe driving',
            'identity_mismatch' => 'Not the person in the app',
            'payment' => 'Payment',
            'no_show' => 'No-show',
            'lost_item' => 'Lost item',
            'other' => 'Other',
        ],
        'reported_by' => 'From :reporter · about :reported',
        'nobody_named' => 'nobody named',
        'severity' => [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'critical' => 'Critical',
        ],
        'status' => [
            'open' => 'Open',
            'under_review' => 'Under review',
            'escalated' => 'Escalated',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
        ],
        'due' => 'Respond by',
        'handled_by' => 'Handled by',
        'unassigned' => 'Nobody yet',
        'evidence' => 'Evidence',
        'files' => '{0} No files|{1} :count file|[2,*] :count files',
        'take' => 'Take this case',
        'take_over' => 'Take over',
        'escalate' => 'Escalate',
        'resolve' => 'Resolve',
        'close' => 'Close without action',
        'message' => 'message',
        'hint_escalate' => 'Why it is going beyond this desk. Internal — the reporter does not see this.',
        'hint_resolve' => 'What was done. The reporter reads this exactly as you write it.',
        'hint_close' => 'Why nothing more can be done. The reporter reads this exactly as you write it.',
        'confirm_escalate' => 'Escalate',
        'confirm_resolve' => 'Resolve and tell the reporter',
        'confirm_close' => 'Close and tell the reporter',
        'no_reports' => 'No open reports.',
        'acknowledged' => 'The alert is yours. The member now sees that somebody is on it.',
        'alert_closed' => 'Alert closed.',
        'taken' => 'The case is yours.',
        'decided_escalate' => 'Escalated.',
        'decided_resolve' => 'Resolved. The reporter can read your message.',
        'decided_close' => 'Closed. The reporter can read your message.',
        'banner' => 'An SOS raised :ago has nobody on it.',
        'banner_silent' => 'A silent alert raised :ago has nobody on it.',
        'open' => 'Open safety cases',
    ],

    'trips' => [
        'title' => 'Live trips',
        'in_progress' => '{0} No trips on the road|{1} :count trip on the road|[2,*] :count trips on the road',
        'refreshed' => 'refreshed :time',
        'flagged_only' => 'Only trips that need a look',
        'women_only' => 'Women only',
        'departs' => 'Departs :time',
        'seats' => ':taken of :total seats',
        'status' => [
            'preparing' => 'Preparing',
            'en_route' => 'Collecting',
            'at_pickup' => 'At pickup',
            'in_progress' => 'Under way',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'emergency' => 'Emergency',
        ],
        'flags' => [
            'alert' => 'Live SOS',
            'emergency' => 'Emergency',
            'deviation' => 'Off route by :meters m',
            'silent' => 'GPS silent',
        ],
        'track' => 'Track',
        'hide' => 'Hide',
        'open_case' => 'Open case',
        'last_position' => 'Last position',
        'no_position' => 'No position received yet',
        'plate' => 'Plate',
        'departed' => 'Set off',
        'not_yet' => 'Not yet',
        'riders' => 'Riders',
        'nothing_flagged' => 'Nothing on the road needs a look right now.',
        'empty' => 'No trips on the road right now.',
    ],

    'pager' => [
        'label' => 'Pagination',
        'previous' => 'Previous',
        'next' => 'Next',
        'position' => 'Page :page of :last · :total waiting',
        'position_trips' => 'Page :page of :last · :total on the road',
        'position_open' => 'Page :page of :last · :total open',
    ],

];
