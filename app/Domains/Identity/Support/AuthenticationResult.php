<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Enums\AccountState;
use App\Domains\Identity\Enums\NextStep;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;

/**
 * The outcome of a verified sign-in: who it was, on which device, whether
 * the account had to be created, where the app goes next, and the token
 * pair. Assembled once by the Action so the Resource has nothing left to
 * decide.
 */
final readonly class AuthenticationResult
{
    public function __construct(
        public User $user,
        public Device $device,
        public AccountState $accountState,
        public NextStep $nextStep,
        public IssuedSession $session,
    ) {}
}
