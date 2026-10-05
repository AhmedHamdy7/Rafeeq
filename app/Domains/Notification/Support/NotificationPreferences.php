<?php

namespace App\Domains\Notification\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationCategory;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Models\NotificationPreference;

/**
 * Whether a member wants to hear about a category on a channel (Chapter 11 §Preferences).
 *
 * Two defaults, and both are decisions:
 *
 * - **Safety is always on.** Chapter 11: "Safety alerts (cannot be fully disabled)". A stored
 *   `false` for safety is ignored here, not merely refused at the endpoint — so a row written
 *   any other way still cannot silence it.
 * - **Marketing is off until switched on.** Everything else defaults to on: those are messages
 *   about things the member did (a booking, a trip). Marketing is the platform talking about
 *   itself, and opting somebody in by default is the wrong side of consent.
 */
final class NotificationPreferences
{
    /** The channels a member controls. SMS and email exist in the schema and are not sent yet. */
    public const array CHANNELS = [NotificationChannel::Push, NotificationChannel::InApp];

    public static function allows(User $user, NotificationCategory $category, NotificationChannel $channel): bool
    {
        if ($category === NotificationCategory::Safety) {
            return true;
        }

        $stored = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('category', $category->value)
            ->where('channel', $channel->value)
            ->value('enabled');

        return $stored === null ? self::default($category) : (bool) $stored;
    }

    public static function default(NotificationCategory $category): bool
    {
        return $category !== NotificationCategory::Marketing;
    }
}
