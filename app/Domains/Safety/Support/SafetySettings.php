<?php

namespace App\Domains\Safety\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * The numbers behind the safety features.
 *
 * Same contract as the other settings readers: `platform_settings` wins, `config/rafeeq.php` holds
 * the shipped default (standard #11).
 *
 * 🔴 These need to move faster than a deploy more than most. The SOS countdown and the report rate
 * limit are both compromises whose right value only real incidents will reveal, and the first week
 * a safety team spends watching them is the week they will want to change them.
 */
final class SafetySettings
{
    /**
     * How long somebody has to take back an SOS before it is treated as real.
     *
     * The countdown runs on the phone; this is what it counts down and what gets copied onto the
     * row, so a review later can ask "how long did she have to cancel" and get the number that was
     * in force then.
     */
    public static function sosCountdownSeconds(): int
    {
        return max(1, (int) self::get('safety.sos_countdown_seconds'));
    }

    /**
     * How many emergency contacts one person may keep.
     *
     * 🔒 A limit because this list is who gets told where somebody is, and an unbounded one is a way
     * to broadcast a person's movements to a crowd.
     */
    public static function maxEmergencyContacts(): int
    {
        return max(1, (int) self::get('safety.max_emergency_contacts'));
    }

    /**
     * How many reports one person may file in an hour (Chapter 10 §Security).
     *
     * 🔴 Deliberately generous. Somebody in a genuinely bad situation may file two or three in quick
     * succession, and the failure to avoid is refusing a real report — not admitting a spurious one,
     * which a human reads and closes.
     */
    public static function reportsPerHour(): int
    {
        return max(1, (int) self::get('safety.reports_per_hour'));
    }

    /** 🔒 How long evidence attached to a report is kept, in days. */
    public static function evidenceRetentionDays(): int
    {
        return max(1, (int) self::get('safety.evidence_retention_days'));
    }

    /**
     * 🔒 How many files one report may carry.
     *
     * A cap at all, because `incident_evidence` is never deleted: anything written there is written
     * for the whole retention period, so an unbounded upload path is an unbounded commitment.
     */
    public static function maxEvidencePerIncident(): int
    {
        return max(1, (int) self::get('safety.max_evidence_per_incident'));
    }

    /** How long after a journey ends a share link keeps working. */
    public static function liveShareGraceMinutes(): int
    {
        return max(1, (int) self::get('safety.live_share_grace_minutes'));
    }

    /**
     * 🔒 The ceiling on a share whose journey has not finished.
     *
     * The link has to expire even if the driver never taps "complete", and that point is the
     * difference between a safety feature and a standing window onto wherever somebody goes next.
     */
    public static function liveShareMaxMinutes(): int
    {
        return max(1, (int) self::get('safety.live_share_max_minutes'));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
