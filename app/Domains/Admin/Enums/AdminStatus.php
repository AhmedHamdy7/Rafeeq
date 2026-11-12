<?php

namespace App\Domains\Admin\Enums;

enum AdminStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    /*
     * Invited from the dashboard (Team, M) and not yet through the first sign-in: no usable
     * password and no confirmed authenticator. Cannot sign in — only isActive() can.
     */
    case Invited = 'invited';
}
