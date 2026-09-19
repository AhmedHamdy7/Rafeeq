<?php

namespace App\Domains\Identity\Enums;

/**
 * Feeds the women-only hard filter (Bible §15.2) — never rendered in any
 * API response (pitfall #30). Required at registration, not optional.
 */
enum Gender: string
{
    case Woman = 'woman';
    case Man = 'man';
    case PreferNotToSay = 'prefer_not_to_say';
}
