<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Enums\SecurityRiskLevel;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Http\Request;

/**
 * One writer for `security_events`, so the audit trail has a single shape
 * and one place enforces the rule the schema only states in a comment:
 * never a secret in `metadata`.
 *
 * Events are written for anonymous callers too (an OTP request happens
 * before we know who — or whether — the account is), hence the nullable
 * user and device.
 */
final class SecurityLog
{
    public static function record(
        SecurityEventType $type,
        ?User $user = null,
        ?Device $device = null,
        array $metadata = [],
        ?SecurityRiskLevel $riskLevel = null,
    ): SecurityEvent {
        return SecurityEvent::create([
            'user_id' => $user?->id,
            'device_id' => $device?->id,
            'event_type' => $type->value,
            'risk_level' => ($riskLevel ?? $type->defaultRiskLevel())->value,
            'metadata' => $metadata === [] ? null : self::scrub($metadata),
        ]);
    }

    /**
     * Context worth keeping for an investigation, with nothing worth
     * stealing. The IP is hashed rather than stored: it answers "same
     * source?" for a rate-limit investigation without retaining a
     * personally identifying address (§18 retention policy).
     */
    public static function requestContext(Request $request): array
    {
        return [
            'ip_hash' => self::hashIp($request->ip()),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ];
    }

    public static function hashIp(?string $ip): ?string
    {
        return $ip === null ? null : hash('sha256', $ip);
    }

    /**
     * A phone number in an audit row is a privacy liability and, in an OTP
     * event, is the very thing an attacker enumerating numbers would want
     * back. Keep only the tail, which is enough for a human reading the
     * admin dashboard to recognise the account.
     */
    public static function maskPhone(PhoneNumber|string $phone): string
    {
        $e164 = $phone instanceof PhoneNumber ? $phone->e164 : $phone;

        return str_repeat('*', max(strlen($e164) - 4, 0)).substr($e164, -4);
    }

    /**
     * Last line of defence: even if a caller passes something sensitive,
     * it never reaches the table. Comparison is on the key name because
     * that is what a mistake actually looks like — `['code' => '482931']`.
     */
    private static function scrub(array $metadata): array
    {
        $forbidden = ['code', 'otp', 'code_hash', 'pin', 'token', 'refresh_token', 'access_token', 'password'];

        foreach (array_keys($metadata) as $key) {
            if (in_array(strtolower((string) $key), $forbidden, true)) {
                $metadata[$key] = '[redacted]';
            }
        }

        return $metadata;
    }
}
