<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Support\DriverApplicationChecklist;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Verification\Actions\SubmitVerificationAction;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use Illuminate\Support\Facades\DB;

final readonly class AutoSubmitDriverApplicationAction
{
    private const array IDENTITY = [VerificationType::GovernmentId, VerificationType::Selfie];

    public function __construct(
        private SubmitDriverApplicationAction $submitApplication,
        private SubmitVerificationAction $submitVerification,
    ) {}

    public function attempt(User $user): ?DriverProfile
    {
        $profile = DriverProfile::query()->whereKey($user->id)->first();

        if ($profile === null
            || ! DriverApplicationState::isSubmittable($profile->status)
            || ! $profile->hasValidLicence()) {
            return null;
        }

        $identity = array_map(fn (VerificationType $type) => $type->value, self::IDENTITY);

        if (array_diff(DriverApplicationChecklist::missingFor($profile), $identity) !== []) {
            return null;
        }

        try {
            return DB::transaction(function () use ($user, $profile): DriverProfile {
                foreach (self::IDENTITY as $type) {
                    $status = $user->verifications()->where('type', $type->value)->first()?->status;

                    if (! in_array($status, [VerificationStatus::Pending, VerificationStatus::Approved], true)) {
                        $this->submitVerification->execute($user, $type);
                    }
                }

                return $this->submitApplication->submit($profile);
            });
        } catch (DomainException) {
            return null;
        }
    }
}
