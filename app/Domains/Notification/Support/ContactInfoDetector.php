<?php

namespace App\Domains\Notification\Support;

/**
 * Whether a chat message carries a way to reach somebody off the platform — the Bible's
 * `contains_contact_info` ("كشف محاولة تبادل أرقام").
 *
 * Detected and FLAGGED, not refused — and that is the schema's own reading: a column that
 * records "this message contained a number" exists because the message was kept. The member is
 * still allowed to say "call me on…"; the flag lets the recipient's app warn before they act on
 * it, and lets the safety desk see it if the conversation is ever reported.
 *
 * Deliberately loose: Arabic-Indic digits, spaces and dashes between digits, a leading +20. A
 * missed number costs more than a flagged order number.
 */
final class ContactInfoDetector
{
    public static function contains(string $text): bool
    {
        $normalised = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        // Digits with separators collapsed: "010 1234 5678", "010-1234-5678".
        $digitsOnly = preg_replace('/(?<=\d)[\s.\-()]+(?=\d)/u', '', $normalised) ?? $normalised;

        return preg_match('/(\+?20|0)?1[0125]\d{8}/', $digitsOnly) === 1
            || preg_match('/\d{9,}/', $digitsOnly) === 1
            || preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $normalised) === 1;
    }
}
