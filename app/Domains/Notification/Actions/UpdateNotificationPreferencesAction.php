<?php

namespace App\Domains\Notification\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationCategory;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Models\NotificationPreference;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * A member switching categories of notification on or off.
 *
 * Refuses the whole request if any entry tries to turn safety off — rather than applying the
 * rest and quietly dropping that one, which would leave the app's switch showing "off" for
 * something that is still on.
 */
final readonly class UpdateNotificationPreferencesAction
{
    /**
     * @param  list<array{category: string, channel: string, enabled: bool}>  $preferences
     */
    public function execute(User $user, array $preferences): void
    {
        foreach ($preferences as $preference) {
            if ($preference['category'] === NotificationCategory::Safety->value && ! $preference['enabled']) {
                throw DomainException::of(ErrorCode::NotificationCategoryLocked);
            }
        }

        DB::transaction(function () use ($user, $preferences): void {
            foreach ($preferences as $preference) {
                NotificationPreference::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'category' => NotificationCategory::from($preference['category'])->value,
                        'channel' => NotificationChannel::from($preference['channel'])->value,
                    ],
                    ['enabled' => (bool) $preference['enabled']],
                );
            }
        });
    }
}
