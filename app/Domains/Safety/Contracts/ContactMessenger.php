<?php

namespace App\Domains\Safety\Contracts;

use RuntimeException;

interface ContactMessenger
{
    public function channel(): string;

    public function keepsPreview(): bool;

    /**
     * @throws RuntimeException
     */
    public function send(string $e164, string $text): void;
}
