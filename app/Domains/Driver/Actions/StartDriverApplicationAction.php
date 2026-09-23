<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Support\DriverEligibility;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * Opens a driver application (Chapter 3 §3).
 *
 * The eligibility checks run here as well as on the screen that offered the
 * button, because a client-side check is a courtesy and this is the rule. The
 * refusal carries the same blocker list the screen would have shown, so the
 * app can explain itself without a second round trip.
 */
final readonly class StartDriverApplicationAction
{
    public function execute(User $user): DriverProfile
    {
        $eligibility = DriverEligibility::for($user);

        if (! $eligibility['eligible']) {
            throw DomainException::of(ErrorCode::DriverNotEligible, fields: [
                'eligibility' => array_column($eligibility['blockers'], 'reason'),
            ]);
        }

        // Returning to a draft — or to one that was rejected and is being
        // fixed — continues it rather than starting again, so nothing already
        // uploaded is lost.
        //
        // Not `firstOrNew(['user_id' => ...])`: that mass-assigns the key, and
        // `user_id` is deliberately not fillable — a primary key must never
        // arrive from a request. The attributes are set directly instead.
        $existing = DriverProfile::query()->whereKey($user->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $profile = new DriverProfile;

        $profile->user_id = $user->id;
        $profile->status = DriverProfileStatus::Draft->value;
        $profile->save();

        return $profile;
    }
}
