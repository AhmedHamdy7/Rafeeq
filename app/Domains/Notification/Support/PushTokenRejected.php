<?php

namespace App\Domains\Notification\Support;

use App\Domains\Notification\Jobs\DeliverPushNotification;
use RuntimeException;

/**
 * The push service says this device token is dead — the app was uninstalled, or the token was
 * rotated (FCM `UNREGISTERED`).
 *
 * A separate exception because the right response is different from every other failure: retrying
 * cannot help, and the token should be forgotten so the next message does not try it again.
 * {@see DeliverPushNotification} does both.
 */
final class PushTokenRejected extends RuntimeException {}
