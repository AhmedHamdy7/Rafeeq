<?php

return [

    /*
     * Shown on the "Become a Driver" screen when a requirement is not met.
     * Chapter 3 §2: explain exactly why, and offer the next step. Each of
     * these is read out loud to the person, so it says what they can do — not
     * what the check was called.
     */
    'eligibility' => [
        'account_not_active' => 'Your account needs attention before you can drive with Rafeeq.',
        'phone_not_verified' => 'Please verify your mobile number first.',
        'profile_incomplete' => 'Please finish your profile first.',
        'date_of_birth_missing' => 'Please add your date of birth to your profile.',
        'below_minimum_age' => 'You need to be older to drive with Rafeeq.',
        'identity_not_verified' => 'Please verify your identity before applying to drive.',
        'already_a_driver' => 'You are already an approved driver.',
        'application_under_review' => 'Your application is being reviewed. This usually takes 24 to 48 hours.',
        'driver_suspended' => 'Your driver account is suspended. Please contact support.',
    ],

    'application' => [
        'submitted' => 'Your application is with our team. Reviews usually take 24 to 48 hours.',
    ],

];
