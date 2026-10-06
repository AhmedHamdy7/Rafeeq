<?php

namespace App\Domains\Admin\Support;

/**
 * Every number the platform reads from `platform_settings`, and what it may be set to.
 *
 * 🔴 A whitelist, and the SETTINGS page edits nothing that is not in it. `platform_settings`
 * is a key-value table the code reads by name, so a page that could write any key would let
 * a typo create a setting nothing reads (and leave the real one unchanged), or let somebody
 * write a value into a key whose reader casts it to something absurd. Each entry here pins
 * the key to the code that reads it and gives it a range a human chose.
 *
 * `SettingsCatalogueTest` scans the code for every key read through the settings classes and
 * fails if one is missing here, or if one here is read by nothing — so this list cannot drift
 * from what the application actually does.
 *
 * The bounds are guard-rails, not recommendations: wide enough for any sensible decision,
 * narrow enough that a slip of the keyboard (a retention of 9 days instead of 90, an OTP
 * that lives for 12,000 seconds) is refused rather than shipped.
 *
 * `locked` keys are shown but not editable here, each with the reason.
 */
final class SettingsCatalogue
{
    /**
     * @var array<string, array{group: string, min: int, max: int, unit: string, locked?: string}>
     */
    public const array ENTRIES = [
        // ---- Sign-in (Chapter 2) ------------------------------------------
        'auth.otp.length' => ['group' => 'auth', 'min' => 4, 'max' => 8, 'unit' => 'digits',
            'locked' => 'client_contract'],
        'auth.otp.ttl_seconds' => ['group' => 'auth', 'min' => 60, 'max' => 900, 'unit' => 'seconds'],
        'auth.otp.resend_cooldown_seconds' => ['group' => 'auth', 'min' => 15, 'max' => 300, 'unit' => 'seconds'],
        'auth.otp.max_attempts' => ['group' => 'auth', 'min' => 3, 'max' => 10, 'unit' => 'attempts'],
        'auth.otp.max_resends' => ['group' => 'auth', 'min' => 1, 'max' => 10, 'unit' => 'times'],
        'auth.pin.length' => ['group' => 'auth', 'min' => 4, 'max' => 6, 'unit' => 'digits',
            'locked' => 'client_contract'],
        'auth.session.access_ttl_minutes' => ['group' => 'auth', 'min' => 5, 'max' => 60, 'unit' => 'minutes'],
        'auth.session.refresh_ttl_days' => ['group' => 'auth', 'min' => 1, 'max' => 180, 'unit' => 'days'],
        'auth.rate_limits.otp_requests_per_phone_per_hour' => ['group' => 'auth', 'min' => 1, 'max' => 20, 'unit' => 'per_hour'],
        'auth.rate_limits.otp_requests_per_ip_per_hour' => ['group' => 'auth', 'min' => 5, 'max' => 200, 'unit' => 'per_hour'],
        'auth.rate_limits.otp_verifications_per_challenge_per_minute' => ['group' => 'auth', 'min' => 3, 'max' => 30, 'unit' => 'per_minute'],

        // ---- Profile -----------------------------------------------------
        'profile.minimum_age_years' => ['group' => 'profile', 'min' => 16, 'max' => 25, 'unit' => 'years'],
        'profile.full_name_min_length' => ['group' => 'profile', 'min' => 2, 'max' => 10, 'unit' => 'characters'],
        'profile.full_name_max_length' => ['group' => 'profile', 'min' => 20, 'max' => 100, 'unit' => 'characters'],

        // ---- Verification -------------------------------------------------
        'verification.document_retention_days' => ['group' => 'verification', 'min' => 30, 'max' => 730, 'unit' => 'days'],

        // ---- Commutes and pricing (Chapter 4, ERD §23.3) ------------------
        'commute.min_price_piastres' => ['group' => 'commute', 'min' => 1_000, 'max' => 20_000, 'unit' => 'piastres'],
        'commute.max_price_piastres' => ['group' => 'commute', 'min' => 2_000, 'max' => 50_000, 'unit' => 'piastres'],
        'pricing.cost_per_km_piastres' => ['group' => 'commute', 'min' => 100, 'max' => 5_000, 'unit' => 'piastres'],
        'pricing.assumed_occupancy' => ['group' => 'commute', 'min' => 1, 'max' => 7, 'unit' => 'passengers'],
        'pricing.rounding_step_piastres' => ['group' => 'commute', 'min' => 100, 'max' => 2_500, 'unit' => 'piastres'],

        // ---- Money -------------------------------------------------------
        // Decided 2026-10-06: deducted from the driver's price, 3% by default, editable here.
        'booking.platform_fee_percent' => ['group' => 'booking', 'min' => 0, 'max' => 20, 'unit' => 'percent'],

        // ---- The trip itself (Chapter 8) ---------------------------------
        'trip.start_window_minutes' => ['group' => 'trip', 'min' => 15, 'max' => 240, 'unit' => 'minutes'],
        'trip.wait_grace_seconds' => ['group' => 'trip', 'min' => 60, 'max' => 900, 'unit' => 'seconds'],
        'trip.wait_extension_seconds' => ['group' => 'trip', 'min' => 30, 'max' => 600, 'unit' => 'seconds'],
        'trip.dispute_window_hours' => ['group' => 'trip', 'min' => 1, 'max' => 168, 'unit' => 'hours'],
        'trip.location_batch_max' => ['group' => 'trip', 'min' => 10, 'max' => 500, 'unit' => 'points'],
        'trip.location_clock_skew_seconds' => ['group' => 'trip', 'min' => 10, 'max' => 900, 'unit' => 'seconds'],
        'trip.location_max_accuracy_meters' => ['group' => 'trip', 'min' => 50, 'max' => 5_000, 'unit' => 'meters'],
        'trip.location_retention_days' => ['group' => 'trip', 'min' => 30, 'max' => 730, 'unit' => 'days'],
        'trip.deviation_threshold_meters' => ['group' => 'trip', 'min' => 200, 'max' => 10_000, 'unit' => 'meters'],
        'trip.gps_silence_alert_seconds' => ['group' => 'trip', 'min' => 30, 'max' => 1_800, 'unit' => 'seconds'],

        // ---- Ratings (Chapter 9) -----------------------------------------
        'rating.window_days' => ['group' => 'rating', 'min' => 1, 'max' => 30, 'unit' => 'days'],
        'rating.edit_window_minutes' => ['group' => 'rating', 'min' => 0, 'max' => 120, 'unit' => 'minutes'],

        // ---- Safety (Chapter 10) -----------------------------------------
        'safety.night_escort_enabled' => ['group' => 'safety', 'min' => 0, 'max' => 1, 'unit' => 'switch'],
        'safety.escort_starts_hour' => ['group' => 'safety', 'min' => 17, 'max' => 23, 'unit' => 'hour_of_day'],
        'safety.escort_ends_hour' => ['group' => 'safety', 'min' => 3, 'max' => 9, 'unit' => 'hour_of_day'],
        'safety.sos_countdown_seconds' => ['group' => 'safety', 'min' => 3, 'max' => 30, 'unit' => 'seconds'],
        'safety.max_emergency_contacts' => ['group' => 'safety', 'min' => 1, 'max' => 10, 'unit' => 'contacts'],
        'safety.reports_per_hour' => ['group' => 'safety', 'min' => 3, 'max' => 100, 'unit' => 'per_hour'],
        'safety.max_evidence_per_incident' => ['group' => 'safety', 'min' => 1, 'max' => 20, 'unit' => 'files'],
        'safety.evidence_retention_days' => ['group' => 'safety', 'min' => 90, 'max' => 3_650, 'unit' => 'days'],
        'safety.live_share_grace_minutes' => ['group' => 'safety', 'min' => 0, 'max' => 120, 'unit' => 'minutes'],
        'safety.live_share_max_minutes' => ['group' => 'safety', 'min' => 30, 'max' => 720, 'unit' => 'minutes'],

        // ---- Trip chat (Chapter 11) ---------------------------------------
        'chat.opens_hours_before' => ['group' => 'chat', 'min' => 1, 'max' => 72, 'unit' => 'hours'],
        'chat.grace_minutes' => ['group' => 'chat', 'min' => 0, 'max' => 1_440, 'unit' => 'minutes'],
        'chat.max_hours_after_departure' => ['group' => 'chat', 'min' => 2, 'max' => 48, 'unit' => 'hours'],
        'chat.messages_per_minute' => ['group' => 'chat', 'min' => 5, 'max' => 120, 'unit' => 'per_minute'],

        // ---- Operations (Chapter 12) -------------------------------------
        'admin.suspension_review_hours' => ['group' => 'admin', 'min' => 1, 'max' => 168, 'unit' => 'hours'],
    ];

    public const array GROUPS = ['auth', 'profile', 'commute', 'booking', 'trip', 'rating', 'safety', 'chat', 'admin'];

    /**
     * @return array{group: string, min: int, max: int, unit: string, locked?: string}|null
     */
    public static function entry(string $key): ?array
    {
        return self::ENTRIES[$key] ?? null;
    }

    /**
     * What the code falls back to when nobody has set this — the shipped default.
     */
    public static function defaultFor(string $key): mixed
    {
        return config("rafeeq.{$key}");
    }

    /**
     * The key as a translation key: `trip.wait_grace_seconds` → `trip__wait_grace_seconds`,
     * because a dot in a translation key is a level of nesting.
     */
    public static function labelKey(string $key): string
    {
        return 'admin.settings.keys.'.str_replace('.', '__', $key);
    }
}
