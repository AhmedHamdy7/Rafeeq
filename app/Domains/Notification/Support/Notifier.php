<?php

namespace App\Domains\Notification\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Jobs\DeliverPushNotification;
use App\Domains\Notification\Models\Notification;

/**
 * 🔒 The one way the platform tells a member something (Chapter 11).
 *
 * Every producer — an operator picking up an SOS, a driver approving a seat — calls this and
 * nothing else, so three rules hold everywhere at once:
 *
 * 1. **The inbox row is written in the caller's transaction.** If the approval rolls back, so
 *    does "your seat is approved". A message about something that did not happen is worse than
 *    no message.
 * 2. **The push goes AFTER commit**, on the queue (`afterCommit`). A phone buzzing for an
 *    approval the database then refused is the same lie, told louder. And a slow push service
 *    must never hold a seat lock.
 * 3. **The words are rendered in the recipient's language, not the caller's.** The driver who
 *    approves in English does not decide that the passenger reads English.
 *
 * `quiet` sends to the inbox only, never to the phone. It exists for one case and matters
 * there: a discreet SOS. A notification that makes the phone buzz in a car with the person she
 * raised it about is the one thing the silent alert promised would not happen.
 *
 * Notifying never fails the action it reports on. A member's seat is approved whether or not a
 * phone could be reached.
 */
final class Notifier
{
    /**
     * @param  array<string, string|int>  $params  substituted into the wording
     * @param  array<string, string>  $data  deep-link payload for the client (ids, never names)
     */
    public static function send(User $user, NotificationType $type, array $params = [], array $data = [], bool $quiet = false): ?Notification
    {
        $category = $type->category();
        $locale = $user->preferred_language ?: config('app.locale');

        $title = __("notifications.{$type->value}.title", $params, $locale);
        $body = __("notifications.{$type->value}.body", $params, $locale);
        $data = array_map('strval', $data);

        $inbox = null;

        if (NotificationPreferences::allows($user, $category, NotificationChannel::InApp)) {
            $inbox = self::row($user, $type, NotificationChannel::InApp, $title, $body, $data);
            // In the inbox is delivered: there is nothing further for it to wait on.
            $inbox->forceFill(['sent_at' => now()])->save();
        }

        if (! $quiet && NotificationPreferences::allows($user, $category, NotificationChannel::Push)) {
            $devices = $user->devices()
                ->whereNull('revoked_at')
                ->whereNotNull('push_token')
                ->get(['id']);

            foreach ($devices as $device) {
                $push = self::row($user, $type, NotificationChannel::Push, $title, $body, $data);

                DeliverPushNotification::dispatch($push->id, $device->id)->afterCommit();
            }
        }

        return $inbox;
    }

    /**
     * @param  array<string, string>  $data
     */
    private static function row(User $user, NotificationType $type, NotificationChannel $channel, string $title, string $body, array $data): Notification
    {
        return Notification::query()->create([
            'user_id' => $user->id,
            'type' => $type->value,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'channel' => $channel->value,
            'category' => $type->category()->value,
        ]);
    }
}
