<?php

namespace App\Domains\Notification\Jobs;

use App\Domains\Identity\Models\Device;
use App\Domains\Notification\Contracts\PushSender;
use App\Domains\Notification\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hands one push row to the phone service and records what happened on the row.
 *
 * The row is the delivery log: `sent_at` when the service accepted it, `failed_reason` when it
 * did not — including "no provider configured", so an instance without one says so instead of
 * claiming success.
 */
final class DeliverPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $notificationId,
        public readonly string $deviceId,
    ) {}

    public function handle(PushSender $sender): void
    {
        $push = Notification::query()->whereKey($this->notificationId)->first();
        $device = Device::query()->whereKey($this->deviceId)->first();

        if ($push === null || $push->sent_at !== null) {
            return;
        }

        // Signed out or uninstalled since the message was queued: nobody to reach.
        if ($device === null || $device->revoked_at !== null || $device->push_token === null) {
            $push->forceFill(['failed_reason' => 'device_unavailable'])->save();

            return;
        }

        if (! $sender->delivers()) {
            $push->forceFill(['failed_reason' => 'no_push_provider'])->save();

            return;
        }

        try {
            $sender->send($device->push_token, $push->title, $push->body, $push->data ?? []);
        } catch (Throwable $e) {
            // 🔒 The exception's class and code, NOT its message: a provider error can echo the
            // request back, and the request carried the device's token.
            $push->forceFill(['failed_reason' => Str::limit(class_basename($e).' ('.$e->getCode().')', 250)])->save();

            throw $e;
        }

        $push->forceFill(['sent_at' => now(), 'failed_reason' => null])->save();
    }
}
