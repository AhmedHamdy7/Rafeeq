<?php

namespace App\Domains\Identity\Enums;

/**
 * Whether verifying the code created an account or reopened one
 * (Chapter 2 §23.2). Never persisted and — critically — never knowable
 * before the code is verified: that is the whole anti-enumeration
 * guarantee of scenario D.
 *
 * Upper-case values because this is API vocabulary quoted verbatim in the
 * chapter, not a database enum.
 */
enum AccountState: string
{
    case NewUser = 'NEW_USER';
    case ExistingUser = 'EXISTING_USER';
}
