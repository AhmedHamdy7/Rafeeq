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
    public function __construct(private EnrollInitialRoleAction $enrollInitialRole) {}

    /**
     * @param  array<string, mixed>  $attributes  already validated upstream
     */
    public function execute(User $user, array $attributes): User
    {
        /*
         * The design collects `firstName` and `lastName`; the live clients send one `fullName`.
         * Both are accepted and composed here, in one place, so no caller has to know which the
         * app happened to send.
         *
         * 🔴 When the two fields arrive, `public_first_name` is the GIVEN name rather than the
         * first token of a composed string. They are usually the same, and the difference is
         * exactly the case worth getting right: somebody whose given name is two words ("Abdel
         * Rahman Saad") would otherwise be shown to other members as "Abdel", which is not her
         * name. The design makes the promise explicit on the screen — "only your first name and
         * photo are shown to other members" — so when the app tells us which part is the first
         * name, we use it.
         */
        $firstName = isset($attributes['firstName'])
            ? trim((string) $attributes['firstName'])
            : null;

        $fullName = $firstName !== null
            ? trim($firstName.' '.trim((string) ($attributes['lastName'] ?? '')))
            : trim((string) $attributes['fullName']);

        $user->fill([
            'full_name' => $fullName,
            // What other members actually see (Bible §15.2 / pitfall #21):
            // full names stay private within the group, so the public handle
            // is derived here rather than left to each Resource to shorten.
            'public_first_name' => $attributes['publicFirstName']
                ?? $firstName
                ?? self::firstNameOf($fullName),
            'gender' => Gender::from((string) $attributes['gender'])->value,
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

        /*
         * 🔴 The role is now OPTIONAL here (Feature 01 v3 makes it its own step), and this branch
         * is what keeps the change additive at the API edge.
         *
         * A client that still sends `registeredRole` with the profile — which is every client
         * live today — has its role enrolled in the same request and is routed straight Home,
         * exactly as before. A client that omits it gets `ROLE_SELECTION` and calls
         * `POST /account/initial-role` next. Both work, so the app can adopt the new screen on its
         * own schedule instead of on deploy day.
         *
         * Enrolment is delegated rather than duplicated: capability rows, `registered_role`,
         * `initial_role_selected_at` and the default mode all have to move together, and a second
         * copy of that here is a second place for them to fall out of step.
         */
        if (($attributes['registeredRole'] ?? null) !== null) {
            $user = $this->enrollInitialRole->execute(
                $user,
                RegisteredRole::from((string) $attributes['registeredRole']),
            );
        }

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
