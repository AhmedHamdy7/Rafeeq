<?php

namespace App\Domains\Identity\Contracts;

use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\ValueObjects\PhoneNumber;

/**
 * The seam for the still-undecided SMS provider (open question #2 in
 * RAFEEQ_MASTER_PLAN.md §19 — and "is WhatsApp an option?").
 *
 * Everything above this interface is provider-agnostic: `RequestOtpAction`
 * generates, hashes and stores the code, then hands the plaintext to a
 * sender exactly once and forgets it. Picking a provider later means adding
 * one implementation and one line in `config/rafeeq.php` — no change to the
 * OTP logic, the schema, or the tests.
 *
 * Implementations MUST NOT persist, log, or echo back the code.
 */
interface OtpSender
{
    /**
     * @throws DomainException when delivery
     *                         fails, so the caller can answer AUTH_OTP_DELIVERY_FAILED
     *                         instead of falsely claiming the code was sent (scenario I).
     */
    public function send(PhoneNumber $phone, string $code, OtpPurpose $purpose): void;
}
