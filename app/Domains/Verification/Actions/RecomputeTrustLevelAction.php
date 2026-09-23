<?php

namespace App\Domains\Verification\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Support\VerificationRequirements;

/**
 * Keeps `users.trust_level` equal to the number of approved levels.
 *
 * Denormalised deliberately: the women-only matching filter and the ranking
 * query in Phase 6 both need to filter on it, and a correlated count over
 * `user_verifications` inside a geospatial search is exactly the kind of
 * query that stops being affordable at scale.
 *
 * Because it is a cache, it is recomputed from the source rows every time
 * rather than incremented — an increment that runs twice, or misses once,
 * leaves a number that no later event ever corrects.
 *
 * MASTER_PLAN §13 resolved trust levels as a merge: this 0–4 count is the
 * VISIBLE progress in the Verification Centre. It is a different thing from
 * `trust_scores.score`, the internal 0–100 reputation number that Phase 10
 * computes from ratings and history and that is never exposed.
 */
final readonly class RecomputeTrustLevelAction
{
    public function execute(User $user): int
    {
        $approved = $user->verifications()
            ->where('status', VerificationStatus::Approved->value)
            ->whereIn('type', array_map(
                fn ($type) => $type->value,
                VerificationRequirements::levels(),
            ))
            // An expired verification stops counting, so a level whose
            // document lapsed is shown as needing action again rather than
            // silently keeping its badge.
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        if ($user->trust_level !== $approved) {
            // Not fillable: a trust level is derived, never submitted.
            $user->trust_level = $approved;
            $user->save();
        }

        return $approved;
    }
}
