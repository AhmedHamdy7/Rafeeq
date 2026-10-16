<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Commute\Enums\CommuteRuleKey;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPreference;

/**
 * Saving the comfort chips somebody wants remembered (Feature 02 v3: "Comfort & atmosphere").
 *
 * 🔴 Writes `CommuteRuleKey` values onto `user_preferences`, rather than a vocabulary of its own.
 * An earlier version of this created a parallel `user_comfort_preferences` table with its own enum;
 * it duplicated the six chips `CommuteRuleKey` already defines, and nothing read it. See the
 * migration that removed it.
 *
 * 🔒 Saving a default does not filter anything. The passenger's chips reach the matcher as
 * `requiredRules` on a search, where they are a SOFT score (`comfort_score`) and never an
 * exclusion — which is what v3 asks for: "avoid claiming these are strict filters until matching
 * rules are defined." Remembering her answer changes which searches are prefilled, not which
 * commutes exist.
 */
final readonly class UpdateComfortPreferencesAction
{
    /**
     * @param  array<int, string>  $rules  already validated as CommuteRuleKey values
     */
    public function execute(User $user, Capability $mode, array $rules): UserPreference
    {
        $preference = UserPreference::forUser($user);

        // Normalised and de-duplicated, so `['quiet','quiet']` from a double-tap stores once and
        // the stored order is stable enough to compare in a test.
        $clean = array_values(array_unique(array_map(
            fn (string $rule): string => CommuteRuleKey::from($rule)->value,
            $rules,
        )));

        sort($clean);

        if ($mode === Capability::Driver) {
            $preference->driver_comfort_rules = $clean;
        } else {
            $preference->passenger_comfort_rules = $clean;
        }

        $preference->save();

        return $preference;
    }
}
