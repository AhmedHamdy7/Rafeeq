<?php

namespace App\Domains\Rating\Support;

use App\Domains\Admin\Models\PlatformSetting;

/**
 * The numbers behind double-blind rating.
 *
 * Same contract as the other settings readers: `platform_settings` wins, `config/rafeeq.php` holds
 * the shipped default (standard #11).
 */
final class RatingSettings
{
    /**
     * 🔴 How long a completed journey stays rateable, in days — and the same window after which a
     * one-sided rating is revealed anyway.
     *
     * **One number, not two, and that is deliberate.** The Bible says a rating becomes visible when
     * both parties have rated "or seven days pass". If submission outlived that, somebody could
     * wait for the reveal, read what the other person said, and only then write theirs — which is
     * the whole of double-blind defeated through the front door. So the window that reveals is the
     * window that closes.
     */
    public static function windowDays(): int
    {
        return max(1, (int) self::get('rating.window_days'));
    }

    /**
     * How long after submitting somebody may still change what they wrote, in minutes.
     *
     * 🔒 Short, and bounded by something stronger than its own length: an edit is refused the
     * moment the rating becomes visible, whatever the clock says. See `SubmitRatingAction::update`.
     */
    public static function editWindowMinutes(): int
    {
        return max(1, (int) self::get('rating.edit_window_minutes'));
    }

    private static function get(string $key): mixed
    {
        return PlatformSetting::value($key, config("rafeeq.{$key}"));
    }
}
