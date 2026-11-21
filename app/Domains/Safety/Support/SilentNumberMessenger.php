<?php

namespace App\Domains\Safety\Support;

use App\Domains\Safety\Contracts\ContactMessenger;

final readonly class SilentNumberMessenger implements ContactMessenger
{
    public function __construct(private ContactMessenger $inner) {}

    /**
     * @return array<int, string>
     */
    public static function numbers(): array
    {
        $configured = config('rafeeq.safety.silent_phones', []);

        return array_values(array_filter(array_map('trim', is_array($configured) ? $configured : explode(',', (string) $configured))));
    }

    public static function isSilent(string $e164): bool
    {
        return in_array($e164, self::numbers(), true);
    }

    public function channel(): string
    {
        return $this->inner->channel();
    }

    public function keepsPreview(): bool
    {
        return $this->inner->keepsPreview();
    }

    public function send(string $e164, string $text): void
    {
        if (self::isSilent($e164)) {
            return;
        }

        $this->inner->send($e164, $text);
    }
}
