<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\RegisteredRole;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPreference;
use Illuminate\Support\Facades\DB;

/**
 * A passenger adding driving to the same account (F1-7, F2-10; PROF-04, PROF-06, decision E6:
 * "got a car → start driving").
 *
 * Idempotent and OTP-free: the person is already signed in on her own account, and adding a
 * capability grants nothing by itself — publishing still needs an approved driver application and
 * a reviewed car (Feature 02).
 *
 * 🔒 Two things it never does, which is why it is not `POST /account/initial-role` again:
 *
 * - it never rewrites `registered_role`, the intent declared at sign-up and kept as history;
 * - it never switches the person's active mode. She is looking at the passenger home when she taps
 *   "Become a driver"; moving her is the app's call, through `PATCH /account/preferences`.
 *
 * The one exception is an account that never finished choosing a role at all: for that one this IS
 * the role choice, so it is recorded as one rather than leaving onboarding asking again.
 */
final readonly class AddDriverCapabilityAction
{
    public function __construct(private EnrollInitialRoleAction $enroll) {}

    public function execute(User $user): User
    {
        if ($user->initial_role_selected_at === null) {
            return $this->enroll->execute($user, RegisteredRole::Driver);
        }

        DB::transaction(function () use ($user): void {
            EnrollInitialRoleAction::grant($user, [Capability::Driver]);

            // Only a person with no mode at all gets one; anybody else stays where she is.
            $preference = UserPreference::forUser($user);

            if ($preference->last_active_mode === null) {
                $preference->last_active_mode = Capability::Passenger;
                $preference->save();
            }
        });

        return $user->refresh();
    }
}
