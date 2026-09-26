<?php

return [

    'title' => 'Rafeeq Operations',

    'nav' => [
        'verifications' => 'Verification',
        'drivers' => 'Drivers',
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

];
