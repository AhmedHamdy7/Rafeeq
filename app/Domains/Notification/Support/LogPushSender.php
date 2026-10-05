<?php

namespace App\Domains\Notification\Support;

use App\Domains\Notification\Contracts\PushSender;
use Illuminate\Support\Facades\Log;

/**
 * Development transport: writes the message to the log instead of a phone.
 *
 * Unlike `LogOtpSender` it does NOT refuse to run in production, and the difference is
 * deliberate. A missing SMS provider makes sign-in impossible, so failing loudly is right. A
 * missing push provider only means the phone does not buzz — the message is still in the
 * member's in-app inbox — so refusing would turn a degraded feature into a broken booking.
 * Instead `delivers()` is false, and every push row it handles is marked as not delivered with
 * the reason, which the dashboard and the logs can both see.
 *
 * 🔒 The token is not logged: it is a credential for the member's phone.
 */
final class LogPushSender implements PushSender
{
    public function send(string $pushToken, string $title, string $body, array $data): void
    {
        Log::debug('[dev] push', ['title' => $title, 'body' => $body, 'data' => $data]);
    }

    public function delivers(): bool
    {
        return false;
    }
}
