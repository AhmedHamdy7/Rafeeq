<?php

namespace App\Domains\Identity\Enums;

use App\Domains\Identity\Support\LaunchRouter;

/**
 * Where the app should send the person next (Chapter 2 §23.2 vocabulary,
 * quoted verbatim). Never persisted.
 *
 * Deliberately a bare enum: binding standard #12 keeps every domain `Enums`
 * namespace free of Laravel, so the rules that read Eloquent models live in
 * {@see LaunchRouter} instead.
 */
enum NextStep: string
{
    case CreatePin = 'CREATE_PIN';
    case LocalSecuritySetupOrHome = 'LOCAL_SECURITY_SETUP_OR_HOME';
    case CompleteProfile = 'COMPLETE_PROFILE';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
}
