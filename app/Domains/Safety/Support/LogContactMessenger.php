<?php

namespace App\Domains\Safety\Support;

use App\Domains\Safety\Contracts\ContactMessenger;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class LogContactMessenger implements ContactMessenger
{
    public function channel(): string
    {
        return 'sms';
    }

    public function keepsPreview(): bool
    {
        return true;
    }

    public function send(string $e164, string $text): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('LogContactMessenger must never run in production.');
        }

        Log::debug('[dev] Auto-share message', ['to' => $e164]);
    }
}
