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
    case PreferNotToSay = 'prefer_not_to_say';
}
