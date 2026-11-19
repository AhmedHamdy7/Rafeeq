<?php

namespace App\Domains\Shared\Testing;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\ProfileSettings;
use App\Domains\Shared\Support\TestAccounts;
use Illuminate\Support\Facades\Cache;

final class DriverBlocker
{
    private const string GENERIC = 'testing:driver-blocker:';

    private const string BIRTH_DATE = 'testing:driver-blocker-dob:';

    public static function isOn(string $userId): bool
    {
        return TestAccounts::apiEnabled() && Cache::has(self::GENERIC.$userId);
    }

    public static function generic(User $user): void
    {
        Cache::forever(self::GENERIC.$user->id, true);
    }

    public static function underAge(User $user): void
    {
        if (! Cache::has(self::BIRTH_DATE.$user->id)) {
            Cache::forever(self::BIRTH_DATE.$user->id, $user->date_of_birth?->toDateString() ?? '');
        }

        $user->forceFill([
            'date_of_birth' => now('Africa/Cairo')->subYears(ProfileSettings::minimumAgeYears())->addMonth()->toDateString(),
        ])->save();
    }

    public static function clear(User $user): void
    {
        Cache::forget(self::GENERIC.$user->id);

        if (Cache::has(self::BIRTH_DATE.$user->id)) {
            $original = (string) Cache::pull(self::BIRTH_DATE.$user->id);

            $user->forceFill(['date_of_birth' => $original === '' ? null : $original])->save();
        }
    }
}
