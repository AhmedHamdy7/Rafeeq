<?php

namespace App\Domains\Verification\Support;

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Identity\Models\User;

/**
 * Whether a person has a face photo anybody may be shown, and where it is.
 *
 * 🔴 One place, because the rule is narrower than it looks and there are two readers.
 *
 * Feature 02 v3 §2: "Once a Driver's identity is approved, the verified photo replaces initials in
 * **both** modes (Passenger and Driver). A **Passenger-only account always shows initials**."
 *
 * So an approved selfie is NOT by itself enough. A passenger who verified her identity — which many
 * will, because it raises her trust level and some drivers require it — still shows initials. Her
 * photo was collected to prove who she is to a reviewer, not to be published to strangers, and
 * publishing it because she happened to verify would be a quiet change of purpose on data somebody
 * handed over for something else.
 *
 * The driver's photo is published for a reason that does not apply in reverse: she is the one
 * offering to put strangers in her car, and the face a human being checked against an ID is most of
 * what makes that offer credible.
 */
final class Avatar
{
    /**
     * The stored path of this person's publishable avatar, or null when they have none — which is
     * the normal case, and means "draw initials".
     */
    public static function pathFor(User $user): ?string
    {
        if ($user->profile_photo_path === null) {
            return null;
        }

        $user->loadMissing('driverProfile');

        return $user->driverProfile?->status === DriverProfileStatus::Approved
            ? $user->profile_photo_path
            : null;
    }

    public static function existsFor(User $user): bool
    {
        return self::pathFor($user) !== null;
    }
}
