<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Enums\OrgType;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Enums\RegisteredRole;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Str;

/**
 * Minimal profile setup (Chapter 2 §17): the smallest set of facts that
 * makes a usable, trustable passenger identity.
 *
 * Only after this runs may `profile_status` become `basic_complete` — and
 * the CHECK constraint added alongside the registration flow means the
 * database will reject that status if any of the three identity fields is
 * still missing, so "complete" always means complete.
 */
final readonly class CompleteBasicProfileAction
{
    /**
     * @param  array<string, mixed>  $attributes  already validated upstream
     */
    public function execute(User $user, array $attributes): User
    {
        $fullName = trim((string) $attributes['fullName']);

        $user->fill([
            'full_name' => $fullName,
            // What other members actually see (Bible §15.2 / pitfall #21):
            // full names stay private within the group, so the public handle
            // is derived here rather than left to each Resource to shorten.
            'public_first_name' => $attributes['publicFirstName'] ?? self::firstNameOf($fullName),
            'gender' => Gender::from((string) $attributes['gender'])->value,
            'registered_role' => RegisteredRole::from((string) $attributes['registeredRole'])->value,
        ]);

        if (array_key_exists('dateOfBirth', $attributes) && $attributes['dateOfBirth'] !== null) {
            $user->date_of_birth = $attributes['dateOfBirth'];
        }

        if (array_key_exists('preferredLanguage', $attributes) && $attributes['preferredLanguage'] !== null) {
            $user->preferred_language = $attributes['preferredLanguage'];
        }

        if (array_key_exists('orgType', $attributes) && $attributes['orgType'] !== null) {
            $user->org_type = OrgType::from((string) $attributes['orgType'])->value;
        }

        if (array_key_exists('organizationId', $attributes)) {
            $user->organization_id = $attributes['organizationId'];
        }

        $user->profile_status = ProfileStatus::BasicComplete->value;

        $user->save();

        return $user;
    }

    /**
     * First whitespace-separated token, which behaves correctly for Arabic
     * and Latin names alike. `Str::of()` is multibyte-safe — `substr`/
     * `explode` on raw bytes would cut an Arabic name mid-character.
     */
    public static function firstNameOf(string $fullName): string
    {
        $first = Str::of($fullName)->trim()->explode(' ')->first();

        // 50 is the width of `users.public_first_name`, not a policy number,
        // so it stays a literal here rather than moving to platform_settings:
        // making it tunable would let an operator configure a truncating
        // write. A single very long token is trimmed rather than rejected —
        // the full name is what was validated; this is only the display form.
        return Str::limit((string) $first, 50, '');
    }
}
