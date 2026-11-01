<?php

namespace App\Domains\Identity\Enums;

/**
 * Decides who may request a seat on a women-only commute (Feature 03 C-11) — never rendered in any
 * API response (pitfall #30). Required at registration, not optional.
 */
enum Gender: string
{
    case Woman = 'woman';
    case Man = 'man';

    /**
     * 🔴 Legacy only (AUTH-10, follow-up F1-2): the sign-up screen offers Woman / Man, and the API
     * now refuses this value on new input. The case stays so the rows that already hold it still
     * load. Such an account is not a woman for the women-only rule, and is asked to answer again
     * the next time it would matter — never silently mapped to either value, since a guess here
     * is a guess about who sits in a women-only car.
     */
    case PreferNotToSay = 'prefer_not_to_say';

    /**
     * What a person may choose today.
     *
     * @return array<int, self>
     */
    public static function answerable(): array
    {
        return [self::Woman, self::Man];
    }
}
