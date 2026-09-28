<?php

namespace App\Domains\Trip\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * The numbers the trip lifecycle turns on.
 *
 * Same contract as the other settings readers: `platform_settings` wins and
 * `config/rafeeq.php` holds the shipped default, because every one of these is a policy
 * figure that has to move without a deploy (standard #11).
 *
 * 🔴 The wait grace in particular. It is the whole of a compromise between a passenger
 * who is two minutes away and four other people who will be late — and the right number
 * is something operations learns from watching real mornings, not something anybody
 * knows in advance.
 */
final class TripSettings
{
    /** How long before departure a driver may press "start". */
    public static function startWindowMinutes(): int
    {
        return max(1, (int) self::get('trip.start_window_minutes'));
    }

    /** The wait timer's grace period, before a passenger counts as not there. */
    public static function waitGraceSeconds(): int
    {
        return max(1, (int) self::get('trip.wait_grace_seconds'));
    }

    /** The most a driver may add in one extension. */
    public static function waitExtensionSeconds(): int
    {
        return max(1, (int) self::get('trip.wait_extension_seconds'));
    }

    /**
     * How long a passenger has to dispute a trip they were marked present for.
     *
     * 🔒 The safeguard that makes decision D18 — the driver decides whether a passenger
     * travelled — acceptable at all. One party deciding the other's bill needs the other
     * party to be able to say it is wrong.
     */
    public static function disputeWindowHours(): int
    {
        return max(1, (int) self::get('trip.dispute_window_hours'));
    }

    /** How many positions one request may carry. */
    public static function locationBatchMax(): int
    {
        return max(1, (int) self::get('trip.location_batch_max'));
    }

    /** How far ahead of us a device's clock may be and still be believed. */
    public static function locationClockSkewSeconds(): int
    {
        return max(0, (int) self::get('trip.location_clock_skew_seconds'));
    }

    /** The worst accuracy worth keeping, in metres. */
    public static function locationMaxAccuracyMeters(): int
    {
        return max(1, (int) self::get('trip.location_max_accuracy_meters'));
    }

    /**
     * 🔒 How long a GPS trail is kept (ERD §23.4: 90 days).
     *
     * Readable from settings like the rest, but it is a legal boundary rather than a tuning
     * knob — a minute-by-minute record of where somebody was, kept only because a no-show
     * dispute has no other evidence.
     */
    public static function locationRetentionDays(): int
    {
        return max(1, (int) self::get('trip.location_retention_days'));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
