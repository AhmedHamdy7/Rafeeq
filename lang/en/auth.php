<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    /*
     * Why an account is on hold, as the member reads it (screen 35). A category, never the
     * staff note — see SuspensionReason.
     */
    'suspension_reasons' => [
        'safety_report' => 'A safety report is under review, so new bookings are paused.',
        'identity_check' => 'We need to check your identity again, so new bookings are paused.',
        'payment_issue' => 'There is a payment issue on your account, so new bookings are paused.',
        'policy_breach' => 'Your account is under review for a breach of our community rules, so new bookings are paused.',
    ],

];
