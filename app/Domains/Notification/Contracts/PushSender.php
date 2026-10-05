<?php

namespace App\Domains\Notification\Contracts;

/**
 * Hands one message to the phone's push service (FCM / APNs).
 *
 * Throws on failure; the caller records why on the delivery row. The token is the device's,
 * already decrypted — it never leaves this call.
 */
interface PushSender
{
    /**
     * @param  array<string, string>  $data  deep-link payload, flat strings (FCM's rule)
     */
    public function send(string $pushToken, string $title, string $body, array $data): void;

    /**
     * Whether a message handed to this sender actually reaches a phone. `false` for the
     * development transport, so delivery rows on an instance without a provider say so
     * rather than claiming they were sent.
     */
    public function delivers(): bool;
}
