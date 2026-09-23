<?php

namespace App\Domains\Shared\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * Normalizes any Egyptian mobile number (local 01xxxxxxxxx, already-E.164,
 * with country-code prefix, or typed with Arabic-Indic/Eastern Arabic-Indic
 * digits — pitfall #62) down to a single canonical E.164 form, which is the
 * login identity (`users.phone_e164`) throughout the system.
 */
final readonly class PhoneNumber implements Stringable
{
    private const string COUNTRY_CODE = '20';

    private function __construct(public string $e164) {}

    public static function fromRaw(string $raw): self
    {
        $digits = self::stripToDigits($raw);
        $localNumber = self::extractLocalNumber($digits, $raw);

        return new self('+'.self::COUNTRY_CODE.$localNumber);
    }

    private static function stripToDigits(string $raw): string
    {
        $arabicIndic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $easternArabicIndic = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $normalized = str_replace($arabicIndic, $western, $raw);
        $normalized = str_replace($easternArabicIndic, $western, $normalized);

        return preg_replace('/\D/', '', $normalized) ?? '';
    }

    private static function extractLocalNumber(string $digits, string $original): string
    {
        $localNumber = match (true) {
            str_starts_with($digits, '00'.self::COUNTRY_CODE) => substr($digits, 4),
            str_starts_with($digits, self::COUNTRY_CODE) && strlen($digits) === 12 => substr($digits, 2),
            str_starts_with($digits, '0') => substr($digits, 1),
            default => $digits,
        };

        if (! preg_match('/^1[0125]\d{8}$/', $localNumber)) {
            throw new InvalidArgumentException("Invalid Egyptian mobile number: {$original}");
        }

        return $localNumber;
    }

    /**
     * The only form of a number that may appear on an unauthenticated
     * screen (Chapter 2 §23.1): enough for the person to confirm they typed
     * their own number, not enough for someone holding a list of numbers to
     * confirm anything. Renders `+201012345678` as `+20 10 *** 5678`.
     */
    public function masked(): string
    {
        $local = substr($this->e164, strlen(self::COUNTRY_CODE) + 1);

        return sprintf('+%s %s *** %s', self::COUNTRY_CODE, substr($local, 0, 2), substr($local, -4));
    }

    public function equals(self $other): bool
    {
        return $this->e164 === $other->e164;
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
