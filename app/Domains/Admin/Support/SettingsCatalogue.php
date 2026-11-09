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
        'auth.otp.lock_seconds' => ['group' => 'auth', 'min' => 60, 'max' => 3600, 'unit' => 'seconds'],
        'auth.otp.max_resends' => ['group' => 'auth', 'min' => 1, 'max' => 10, 'unit' => 'times'],
        // `auth.pin.length` was here. The local PIN left the MVP (Feature 01 v3 §10), so nothing
        // reads the key any more — and a tunable that no reader consults is worse than no
        // tunable: an operator changes it, the page confirms the change, and nothing happens.
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

        // ---- The automatic seat price (P-1 → P-5; PAY-41 → PAY-43, PAY-48) ----
        'pricing.km_rate_standard_piastres' => ['group' => 'commute', 'min' => 100, 'max' => 5_000, 'unit' => 'piastres'],
        'pricing.km_rate_comfort_piastres' => ['group' => 'commute', 'min' => 100, 'max' => 5_000, 'unit' => 'piastres'],
        // 10% in the beta, 15% at the public launch (PAY-41).
        'pricing.commission_rate_percent' => ['group' => 'commute', 'min' => 0, 'max' => 50, 'unit' => 'percent'],
        'pricing.min_commission_piastres' => ['group' => 'commute', 'min' => 0, 'max' => 5_000, 'unit' => 'piastres'],
        'pricing.rounding_step_piastres' => ['group' => 'commute', 'min' => 100, 'max' => 2_500, 'unit' => 'piastres'],
        'pricing.min_seat_divisor' => ['group' => 'commute', 'min' => 1, 'max' => 7, 'unit' => 'seats'],
        // PROF-28: the fewest seats a car may be approved for at verification.
        'driver.min_approved_seats' => ['group' => 'commute', 'min' => 1, 'max' => 7, 'unit' => 'seats'],

        // ---- Route limits (COM-31, COM-16; decision C8 of 2026-10-31) -----
        // The driver no longer chooses any of these.
        'commute.match_radius_meters' => ['group' => 'commute', 'min' => 200, 'max' => 5_000, 'unit' => 'meters'],
        'commute.far_radius_meters' => ['group' => 'commute', 'min' => 200, 'max' => 10_000, 'unit' => 'meters'],
        'commute.on_way_request_minutes' => ['group' => 'commute', 'min' => 0, 'max' => 60, 'unit' => 'minutes'],
        'commute.far_request_minutes' => ['group' => 'commute', 'min' => 0, 'max' => 60, 'unit' => 'minutes'],
        'commute.on_way_trip_minutes' => ['group' => 'commute', 'min' => 0, 'max' => 90, 'unit' => 'minutes'],
        'commute.far_trip_minutes' => ['group' => 'commute', 'min' => 0, 'max' => 90, 'unit' => 'minutes'],
        'commute.max_pickup_points' => ['group' => 'commute', 'min' => 1, 'max' => 6, 'unit' => 'stops'],
        'commute.max_dropoff_points' => ['group' => 'commute', 'min' => 1, 'max' => 6, 'unit' => 'stops'],
        'commute.schedule_lock_hours' => ['group' => 'commute', 'min' => 1, 'max' => 48, 'unit' => 'hours'],

        // ---- Booking and the waiting list (Feature 03 R-1, BOOK-18) --------
        // How many people may wait on one commute; past it `BOOKING_WAITLIST_FULL`.
        'booking.max_waitlist_size' => ['group' => 'booking', 'min' => 1, 'max' => 50, 'unit' => 'passengers'],

        // ---- The trip itself (Chapter 8) ---------------------------------
        'trip.start_window_minutes' => ['group' => 'trip', 'min' => 15, 'max' => 240, 'unit' => 'minutes'],
        'trip.driver_starting_soon_minutes' => ['group' => 'trip', 'min' => 5, 'max' => 120, 'unit' => 'minutes'],
        'trip.wait_grace_seconds' => ['group' => 'trip', 'min' => 60, 'max' => 900, 'unit' => 'seconds'],
        // The "+2 min" extension is gone (TRIP-11): every passenger gets the same grace.
        'trip.arrival_radius_meters' => ['group' => 'trip', 'min' => 50, 'max' => 1_000, 'unit' => 'meters'],
        'trip.dispute_window_hours' => ['group' => 'trip', 'min' => 1, 'max' => 168, 'unit' => 'hours'],
        'trip.location_batch_max' => ['group' => 'trip', 'min' => 10, 'max' => 500, 'unit' => 'points'],
        'trip.location_clock_skew_seconds' => ['group' => 'trip', 'min' => 10, 'max' => 900, 'unit' => 'seconds'],
        'trip.location_max_accuracy_meters' => ['group' => 'trip', 'min' => 50, 'max' => 5_000, 'unit' => 'meters'],
        'trip.location_retention_days' => ['group' => 'trip', 'min' => 30, 'max' => 730, 'unit' => 'days'],
        'trip.deviation_threshold_meters' => ['group' => 'trip', 'min' => 200, 'max' => 10_000, 'unit' => 'meters'],
        'trip.gps_silence_alert_seconds' => ['group' => 'trip', 'min' => 30, 'max' => 1_800, 'unit' => 'seconds'],
        // The 1-hour and 10-minute reminders (Feature 03 files §8). Filed with the trip: they are about the day.
        'notifications.trip_reminder_early_minutes' => ['group' => 'trip', 'min' => 15, 'max' => 240, 'unit' => 'minutes'],
        'notifications.trip_reminder_final_minutes' => ['group' => 'trip', 'min' => 1, 'max' => 30, 'unit' => 'minutes'],

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
        // Feature 03 files X-7, SAFE-08: a report about a journey within 24 hours of it.
        'safety.report_window_hours' => ['group' => 'safety', 'min' => 1, 'max' => 168, 'unit' => 'hours'],
        'safety.max_evidence_per_incident' => ['group' => 'safety', 'min' => 1, 'max' => 20, 'unit' => 'files'],
        'safety.evidence_retention_days' => ['group' => 'safety', 'min' => 90, 'max' => 3_650, 'unit' => 'days'],
        'safety.live_share_grace_minutes' => ['group' => 'safety', 'min' => 0, 'max' => 120, 'unit' => 'minutes'],
        'safety.live_share_max_minutes' => ['group' => 'safety', 'min' => 30, 'max' => 720, 'unit' => 'minutes'],

        // ---- Reliability score (Feature 03 files X-3, D3/D4) -------------
        // Points lost per event. Only a number for now: it gates nothing (D4).
        'reliability.window_days' => ['group' => 'reliability', 'min' => 30, 'max' => 365, 'unit' => 'days'],
        // Below the driver-cancel free window (16 h): the mid-notice row is "10–16 h".
        'reliability.late_cancellation_hours' => ['group' => 'reliability', 'min' => 1, 'max' => 15, 'unit' => 'hours'],
        'reliability.weights.driver_no_show' => ['group' => 'reliability', 'min' => 0, 'max' => 50, 'unit' => 'score_points'],
        'reliability.weights.passenger_no_show' => ['group' => 'reliability', 'min' => 0, 'max' => 50, 'unit' => 'score_points'],
        'reliability.weights.driver_cancel_short_notice' => ['group' => 'reliability', 'min' => 0, 'max' => 50, 'unit' => 'score_points'],
        'reliability.weights.driver_cancel_mid_notice' => ['group' => 'reliability', 'min' => 0, 'max' => 50, 'unit' => 'score_points'],
        'reliability.weights.driver_cancel_long_notice' => ['group' => 'reliability', 'min' => 0, 'max' => 50, 'unit' => 'score_points'],
        'reliability.weights.passenger_cancel_short_notice' => ['group' => 'reliability', 'min' => 0, 'max' => 50, 'unit' => 'score_points'],
        'reliability.weights.driver_late' => ['group' => 'reliability', 'min' => 0, 'max' => 50, 'unit' => 'score_points'],
        'reliability.weights.passenger_late' => ['group' => 'reliability', 'min' => 0, 'max' => 50, 'unit' => 'score_points'],
        'reliability.weights.driver_ended_trip' => ['group' => 'reliability', 'min' => 0, 'max' => 50, 'unit' => 'score_points'],

        // ---- Trip chat (Chapter 11) ---------------------------------------
        'chat.opens_hours_before' => ['group' => 'chat', 'min' => 1, 'max' => 72, 'unit' => 'hours'],
        'chat.grace_minutes' => ['group' => 'chat', 'min' => 0, 'max' => 1_440, 'unit' => 'minutes'],
        'chat.max_hours_after_departure' => ['group' => 'chat', 'min' => 2, 'max' => 48, 'unit' => 'hours'],
        'chat.messages_per_minute' => ['group' => 'chat', 'min' => 5, 'max' => 120, 'unit' => 'per_minute'],
        'chat.typing_interval_seconds' => ['group' => 'chat', 'min' => 1, 'max' => 30, 'unit' => 'seconds'],

        // ---- Debt and weekly settlement (PAY-08 → PAY-19, PAY-34) ---------
        // No cap on a driver's debt (PAY-15): the Friday deadline is what stops her.
        'debt.late_fee_percent' => ['group' => 'debt', 'min' => 0, 'max' => 50, 'unit' => 'percent'],
        'debt.weeks_before_suspension' => ['group' => 'debt', 'min' => 2, 'max' => 8, 'unit' => 'weeks'],
        'debt.passenger_suspension_days' => ['group' => 'debt', 'min' => 3, 'max' => 60, 'unit' => 'days'],
        'debt.passenger_suspension_limit_piastres' => ['group' => 'debt', 'min' => 2_000, 'max' => 200_000, 'unit' => 'piastres'],
        'debt.coupon_percent' => ['group' => 'debt', 'min' => 0, 'max' => 100, 'unit' => 'percent'],
        'debt.reminder_hour' => ['group' => 'debt', 'min' => 6, 'max' => 22, 'unit' => 'hour_of_day'],

        // ---- Fees (PAY-20 → PAY-29) -----------------------------------------
        'fees.counter_reset_days' => ['group' => 'fees', 'min' => 7, 'max' => 365, 'unit' => 'days'],
        'fees.no_show_step_2_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.no_show_step_3_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.no_show_step_4_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.no_show_step_5_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.late_cancel_hours' => ['group' => 'fees', 'min' => 1, 'max' => 48, 'unit' => 'hours'],
        'fees.free_cancel_pickup_moved_minutes' => ['group' => 'fees', 'min' => 0, 'max' => 120, 'unit' => 'minutes'],
        'fees.late_cancel_share_percent' => ['group' => 'fees', 'min' => 0, 'max' => 100, 'unit' => 'percent'],
        'fees.lateness_step_3_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.lateness_step_4_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.lateness_step_5_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.appeal_hours' => ['group' => 'fees', 'min' => 12, 'max' => 168, 'unit' => 'hours'],
        'fees.driver_cancel_free_hours' => ['group' => 'fees', 'min' => 1, 'max' => 72, 'unit' => 'hours'],
        'fees.driver_cancel_short_hours' => ['group' => 'fees', 'min' => 0, 'max' => 48, 'unit' => 'hours'],
        'fees.driver_cancel_mid_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.driver_cancel_short_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.driver_active_cancel_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.pro_rata_free_percent' => ['group' => 'fees', 'min' => 0, 'max' => 100, 'unit' => 'percent'],
        'fees.driver_no_show_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.driver_late_percent' => ['group' => 'fees', 'min' => 0, 'max' => 200, 'unit' => 'percent'],
        'fees.early_drop_meters' => ['group' => 'fees', 'min' => 200, 'max' => 5_000, 'unit' => 'meters'],
        'fees.payment_lie_passenger_percent' => ['group' => 'fees', 'min' => 0, 'max' => 300, 'unit' => 'percent'],
        'fees.payment_lie_driver_percent' => ['group' => 'fees', 'min' => 0, 'max' => 300, 'unit' => 'percent'],
        'fees.women_only_misuse_percent' => ['group' => 'fees', 'min' => 0, 'max' => 300, 'unit' => 'percent'],

        // ---- Operations (Chapter 12) -------------------------------------
        'admin.suspension_review_hours' => ['group' => 'admin', 'min' => 1, 'max' => 168, 'unit' => 'hours'],
        'admin.suspension_appeal_hours' => ['group' => 'admin', 'min' => 1, 'max' => 336, 'unit' => 'hours'],
    ];

    public const array GROUPS = ['auth', 'profile', 'commute', 'booking', 'debt', 'fees', 'trip', 'rating', 'reliability', 'safety', 'chat', 'admin'];

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
