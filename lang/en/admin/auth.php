<?php

// Signed-out screens S01–S08 and the idle warning.
return [
    'brand_sub' => 'Operations dashboard',
    'foot' => 'Team accounts only. There is no sign-up: an Admin invites you.',
    'sign_in' => [
        'title' => 'Sign in',
        'text' => 'Use your team email.',
        'email' => 'Email',
        'password' => 'Password',
        'continue' => 'Continue',
        'forgot' => 'Forgot password? Ask an Admin to reset it',
    ],
    'locked' => [
        'title' => 'Sign-in is locked for now',
        'text' => 'Too many wrong attempts. Try again in',
        'note' => 'If this wasn\'t you, tell an Admin. They can see the attempts in the audit log.',
    ],
    'code' => [
        'title' => 'Enter your code',
        'text' => 'Open your authenticator app and type the 6-digit code for RAFEEQ — not the setup key you added to it.',
        'label' => '6-digit code',
        'digit' => 'Digit :n',
        'verify' => 'Verify',
        'lost' => 'Lost your phone? An Admin can reset your two-factor.',
    ],
    'signed_out' => [
        'title' => 'You were signed out',
        'text' => 'Signed out after :minutes minutes without activity, to keep users\' data safe.',
        'again' => 'Sign in again',
    ],
    'idle' => [
        'title' => 'Still there?',
        'text' => 'You\'ll be signed out in :clock because you haven\'t done anything for :minutes minutes.',
        'stay' => 'Stay signed in',
    ],
];
