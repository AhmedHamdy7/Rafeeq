<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Development transport: writes the code to the log so a developer can sign
 * in without an SMS contract.
 *
 * Chapter 2 §26 requires that "OTP never appears in logs" — that rule is
 * about the real system, and this class is the reason it stays true: it
 * hard-refuses to run in production, so nobody can ship without a real
 * provider and quietly keep dumping live codes into a log file. The check is
 * on the environment, not on config, because config is exactly what a
 * misconfigured deploy gets wrong.
 */
final class LogOtpSender implements OtpSender
{
    public function send(PhoneNumber $phone, string $code, OtpPurpose $purpose): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'LogOtpSender is a development transport and must never run in production. '
                .'Configure a real SMS provider via rafeeq.auth.otp.driver.'
            );
        }

        Log::debug('[dev] OTP issued', [
            'phone' => $phone->e164,
            'purpose' => $purpose->value,
            'code' => $code,
        ]);
    }
}
