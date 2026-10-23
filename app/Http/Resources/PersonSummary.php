<?php

namespace App\Http\Resources;

use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;

/**
 * How one member appears to another, everywhere they appear.
 *
 * The prototype shows the same handful of facts about a person on the match details
 * screen, the group's member list and the driver's request review — "⭐ 4.9 · 12 trips ·
 * 96% on-time", with ID and workplace badges. Three screens, one shape, so it lives here
 * rather than being written three times and drifting.
 *
 * 🔒 The privacy line, and it has not moved: a public first name, what has been verified,
 * and what the numbers say. Never a full name, a phone number, an email, or a gender —
 * the last one especially, since a women-only commute would otherwise be a way to read
 * somebody's gender off a members list.
 *
 * 🔴 `sameOrganisation` is a COMPARISON, never a disclosure. The screen says "Same
 * workplace", and it takes the viewer to produce that: a badge naming the employer would
 * tell every passenger on the platform where a driver works, which is most of what
 * somebody needs to wait for her outside it. So the org id is compared and thrown away.
 */
final class PersonSummary
{
    /**
     * @param  User  $person  MUST arrive with `stats` and `verifications` loaded
     * @param  User|null  $viewer  whoever is looking, for the comparison badges
     * @return array<string, mixed>
     */
    public static function for(User $person, ?User $viewer = null, bool $asDriver = false): array
    {
        /*
         * The viewer arrives from the auth guard with nothing loaded, and
         * `sameOrganisation` needs their verifications to know whether their own
         * organisation was ever confirmed. Without this the badge is silently always
         * false — a wrong answer that looks like a working feature.
         *
         * `loadMissing` rather than a relation in every caller's query: it is an explicit
         * eager load (so `preventLazyLoading` is satisfied), it runs once because every
         * row in a list is handed the SAME viewer instance, and it puts the requirement
         * next to the code that has it instead of in eight controllers.
         */
        $viewer?->loadMissing('verifications');

        $stats = $person->stats;

        return [
            'publicFirstName' => $person->public_first_name,
            'trustLevel' => $person->trust_level,

            /*
             * WHICH levels, not just how many. The screens show named badges ("ID
             * verified"), and a trust level of 2 does not say which two — so a client
             * given only the number has to guess, and guesses wrong the moment the
             * levels are reordered.
             */
            'verifiedLevels' => self::verifiedLevels($person),

            // 🔴 A comparison, not a disclosure. See the class note.
            'sameOrganisation' => self::shareAnOrganisation($person, $viewer),

            /*
             * Null until ratings exist (Phase 9), and null is the honest answer: a
             * driver who has not been rated has no rating, and sending 0 would show a
             * new driver a zero-star badge earned by nobody.
             */
            'rating' => self::rating($stats, $asDriver),

            'completedTrips' => $asDriver
                ? (int) ($stats->completed_trips_as_driver ?? 0)
                : (int) ($stats->completed_trips_as_passenger ?? 0),

            /*
             * 🔴 Read from the driver profile when this is the DRIVER side, and that is a fix
             * rather than a nicety.
             *
             * `CompleteTripAction` computes a driver's punctuality into
             * `driver_profiles.on_time_rate`. This method used to read `user_stats.on_time_rate`
             * for both roles — a column no production code writes, only the seeders — so a real
             * driver's computed rate was never shown to anybody. It stayed null for ever while the
             * guide said "null until computed", which made a discarded number look deliberate.
             *
             * Still null for a passenger, and honestly so: nothing computes passenger punctuality
             * yet, and v3 says the two "must be calculated separately".
             */
            'onTimeRate' => self::onTimeRate($person, $stats, $asDriver),
        ];
    }

    /**
     * Whichever of the two punctuality figures belongs to the role being shown.
     */
    private static function onTimeRate(User $person, mixed $stats, bool $asDriver): ?float
    {
        if ($asDriver) {
            $person->loadMissing('driverProfile');

            $rate = $person->driverProfile?->on_time_rate;

            return $rate === null ? null : (float) $rate;
        }

        return $stats?->on_time_rate === null ? null : (float) $stats->on_time_rate;
    }

    /**
     * The levels this person has actually passed, as their enum values.
     *
     * Expired levels are excluded: `RecomputeTrustLevelAction` stops counting a lapsed
     * document towards the trust level, so a badge that kept showing would say more than
     * the level it is drawn from.
     *
     * @return array<int, string>
     */
    private static function verifiedLevels(User $person): array
    {
        if (! $person->relationLoaded('verifications')) {
            return [];
        }

        $levels = [];

        /*
         * 🔴 Ordered before anything is read off it. The relation has no `orderBy`, so the badges
         * came back in whatever order the database felt like — stable enough in development to look
         * deliberate, and not stable at all. The identical bug in `UserResource::capabilityValues()`
         * reached CI as a failure that could not be reproduced locally.
         *
         * By the enum's own declaration order, because that IS the product's order of trust:
         * phone, then government id, then selfie, then organisation. A badge row that reshuffled
         * itself between two reads of the same profile would make the strongest thing she has
         * verified appear in a different place each time.
         */
        $inTrustOrder = $person->verifications->sortBy(function ($verification): int {
            $position = array_search($verification->type, VerificationType::cases(), true);

            // A type the enum no longer declares sorts last rather than first: an unknown badge
            // must not push the ones we understand down the row.
            return $position === false ? PHP_INT_MAX : $position;
        });

        // A foreach rather than a filter-then-map: the published contract needs a typed
        // list of strings, and a mapped collection loses its element type (standard #37).
        foreach ($inTrustOrder as $verification) {
            if ($verification->status !== VerificationStatus::Approved) {
                continue;
            }

            if ($verification->expires_at !== null && $verification->expires_at->isPast()) {
                continue;
            }

            $levels[] = $verification->type->value;
        }

        return $levels;
    }

    /**
     * Whether these two are at the same workplace or university.
     *
     * Both sides must have a VERIFIED organisation, not merely a claimed one. An
     * unverified `organization_id` is a text field somebody typed, and a badge built on
     * it would let anyone earn "same workplace" with a guess — which is exactly the badge
     * a passenger uses to decide a stranger is safe.
     */
    private static function shareAnOrganisation(User $person, ?User $viewer): bool
    {
        if ($viewer === null || $person->organization_id === null) {
            return false;
        }

        if ($person->organization_id !== $viewer->organization_id) {
            return false;
        }

        return self::hasVerifiedOrganisation($person) && self::hasVerifiedOrganisation($viewer);
    }

    private static function hasVerifiedOrganisation(User $user): bool
    {
        if (! $user->relationLoaded('verifications')) {
            // Loaded explicitly by every caller; returning false rather than lazy-loading
            // keeps one missing eager load from becoming a query per row in a list.
            return false;
        }

        return $user->verifications->contains(
            fn ($verification) => $verification->type === VerificationType::Organization
                && $verification->status === VerificationStatus::Approved
        );
    }

    private static function rating(mixed $stats, bool $asDriver): ?float
    {
        $value = $asDriver
            ? $stats?->avg_rating_as_driver
            : $stats?->avg_rating_as_passenger;

        return $value === null ? null : (float) $value;
    }
}
