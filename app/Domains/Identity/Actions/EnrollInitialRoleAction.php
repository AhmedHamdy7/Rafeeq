<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\CapabilityStatus;
use App\Domains\Identity\Enums\RegisteredRole;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserCapability;
use App\Domains\Identity\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enrolling the role chosen at the end of onboarding (Feature 01 v3: `POST /me/initial-role`,
 * "Idempotently enroll Passenger, Driver, or Both; mark role completed").
 *
 * 🔴 Idempotent is a hard requirement, not a nicety. This is the last call of a sign-up on a
 * mobile network: a request that times out after committing is retried by the app, and the
 * acceptance criteria say so outright — "repeat role submit does not create duplicate capability
 * rows".
 */
final readonly class EnrollInitialRoleAction
{
    public function execute(User $user, RegisteredRole $role): User
    {
        $capabilities = Capability::forRegisteredRole($role);

        DB::transaction(function () use ($user, $role, $capabilities): void {
            /*
             * 🔴 `insertOrIgnore`, not `firstOrCreate`.
             *
             * `firstOrCreate` reads, then writes, and two retries arriving together both read
             * "absent" and both insert. One then dies on the unique index with a 500 — on the
             * final step of somebody's registration. `insertOrIgnore` leans on the index instead
             * of racing it, in one statement, and the duplicate is simply not inserted.
             */
            UserCapability::query()->insertOrIgnore(array_map(
                fn (Capability $capability): array => [
                    'id' => (string) Str::ulid(),
                    'user_id' => $user->getKey(),
                    'capability' => $capability->value,
                    'status' => CapabilityStatus::Active->value,
                    'enrolled_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                $capabilities,
            ));

            /*
             * Written only the first time. `registered_role` is the intent declared at sign-up and
             * stays as history: somebody who joined as a passenger and later adds driving gains a
             * capability row, and this column still records what she originally came for.
             *
             * ⚠️ So `registered_role` and the capability rows can legitimately disagree — the
             * column may say `passenger` while both capabilities are active. That is the design,
             * not a bug to "fix" by syncing them, and it is why authorization reads the rows.
             */
            if ($user->initial_role_selected_at === null) {
                $user->forceFill([
                    'registered_role' => $role->value,
                    'initial_role_selected_at' => now(),
                ])->save();
            }

            /*
             * Feature 01 v3 §4: Both starts on the Passenger home. Only filled when the person has
             * no mode yet — re-submitting a role must not yank somebody back to a different home
             * than the one they are looking at.
             */
            $preference = UserPreference::forUser($user);

            if ($preference->last_active_mode === null) {
                $preference->last_active_mode = Capability::defaultModeFor($capabilities);
                $preference->save();
            }
        });

        return $user->refresh();
    }

    /*
     * ⚠️ Deliberately NOT here: reactivating a capability whose row is `withdrawn`.
     *
     * `insertOrIgnore` leaves an existing row alone, so a withdrawn capability stays withdrawn.
     * There is no withdraw endpoint in this feature set, which makes the state unreachable from
     * the API today — and inventing a reactivation policy for it would be inventing a pending
     * business rule. When a withdraw flow is specified, the question "does re-enrolling undo it"
     * gets answered there, with whatever notice or cooling-off period that flow decides.
     */
}
