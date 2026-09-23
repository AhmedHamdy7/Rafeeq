<?php

namespace Tests\Support;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use RuntimeException;

/**
 * Captures the codes the system would have texted, which is the only way a
 * test can read one: the plaintext is hashed before it is stored and is
 * never returned by any endpoint. That the tests have to go through this
 * seam is itself the proof of the property.
 */
final class FakeOtpSender implements OtpSender
{
    /** @var array<int, array{phone: string, code: string, purpose: OtpPurpose}> */
    public array $sent = [];

    public bool $shouldFail = false;

    public function send(PhoneNumber $phone, string $code, OtpPurpose $purpose): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('SMS provider unavailable.');
        }

        $this->sent[] = ['phone' => $phone->e164, 'code' => $code, 'purpose' => $purpose];
    }

    public function lastCode(): string
    {
        if ($this->sent === []) {
            throw new RuntimeException('No OTP was sent.');
        }

        return end($this->sent)['code'];
    }
}
