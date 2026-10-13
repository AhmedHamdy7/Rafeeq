<?php

namespace App\Domains\Identity\Enums;

/**
 * Which appearance the person picked (Feature 01 v3: `user_preferences.theme`).
 *
 * Carried by the server only so the choice survives a reinstall or a second device. Nothing on
 * the server renders anything, so this value changes no behaviour here — it is stored, returned,
 * and otherwise left alone.
 *
 * `System` is a real answer and the sensible default, which is why the column is nullable rather
 * than defaulting to `Light`: null means "never asked", and that is not the same as having chosen
 * to follow the device.
 */
enum ThemePreference: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';
}
